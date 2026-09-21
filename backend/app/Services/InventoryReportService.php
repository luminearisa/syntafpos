<?php

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\WarehouseTransfer;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Inventory reports (spec §32): read-only aggregations over the stock ledger.
 *
 * Every report filters in SQL, because the movement tables are the highest
 * volume in the system and a collection filter would not scale (§43). Every
 * figure that is multiplied or added is multiplied or added with DecimalMath,
 * because float drift across thousands of ledger rows would silently misstate a
 * valuation (§46). A report with nothing behind it returns zeros and an empty
 * list rather than a demo figure (§51).
 *
 * Tenancy is two conditions everywhere: the user's own companies, then the
 * resolved company. A joined report cannot use scopeVisibleTo, because the
 * scope's unqualified company_id is ambiguous across the joined label tables,
 * so those queries spell the same predicate out with a qualified column.
 */
class InventoryReportService
{
    public function __construct(protected BusinessContext $context) {}

    /**
     * Stock position per product, warehouse and unit, aggregated from the
     * balance cache. Available is on hand less reserved.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StockBalance>
     */
    public function stockSummary(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->join('units', 'units.id', '=', 'stock_balances.unit_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_balances.product_variant_id')
            ->where($this->tenancy($user, $filters['company_id'], 'stock_balances.company_id'))
            ->select([
                'stock_balances.company_id',
                'stock_balances.product_id',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.category_id',
                'products.brand_id',
                'stock_balances.product_variant_id',
                'product_variants.sku as variant_sku',
                'product_variants.name as variant_name',
                'stock_balances.warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'stock_balances.location_id',
                'stock_balances.unit_id',
                'units.code as unit_code',
                DB::raw('coalesce(sum(stock_balances.on_hand), 0) as on_hand'),
                DB::raw('coalesce(sum(stock_balances.reserved), 0) as reserved'),
                DB::raw('coalesce(sum(stock_balances.incoming), 0) as incoming'),
                DB::raw('coalesce(sum(stock_balances.outgoing), 0) as outgoing'),
                DB::raw('coalesce(max(stock_balances.average_cost), 0) as average_cost'),
                DB::raw('coalesce(max(stock_balances.last_movement_at), null) as last_movement_at'),
            ])
            ->groupBy(
                'stock_balances.company_id',
                'stock_balances.product_id',
                'stock_balances.product_variant_id',
                'stock_balances.warehouse_id',
                'stock_balances.location_id',
                'stock_balances.unit_id'
            )
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('stock_balances.warehouse_id', $id))
            ->when($filters['location_id'], fn ($q, $id) => $q->where('stock_balances.location_id', $id))
            ->when($filters['product_id'], fn ($q, $id) => $q->where('stock_balances.product_id', $id))
            ->when($filters['category_id'], fn ($q, $id) => $q->where('products.category_id', $id))
            ->when($filters['brand_id'], fn ($q, $id) => $q->where('products.brand_id', $id))
            ->when($filters['search'], fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('product_variants.sku', 'like', "%{$search}%")))
            ->orderByRaw('products.name asc, warehouses.name asc, units.code asc');

        return $query->paginate($perPage);
    }

    /**
     * The running ledger of one product, ordered as the movements occurred.
     *
     * The balance column is the recorded balance_after and is never recomputed
     * from the page, so a paginated card stays truthful about the balance at
     * that point in the ledger.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function stockCard(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->join('units', 'units.id', '=', 'stock_movements.unit_id')
            ->where($this->tenancy($user, $filters['company_id'], 'stock_movements.company_id'))
            ->where('stock_movements.product_id', $filters['product_id'])
            ->select([
                'stock_movements.id',
                'stock_movements.occurred_at',
                'stock_movements.movement_type',
                'stock_movements.reference_type',
                'stock_movements.reference_id',
                'stock_movements.warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'stock_movements.location_id',
                'stock_movements.unit_id',
                'units.code as unit_code',
                'stock_movements.quantity',
                'stock_movements.unit_cost',
                'stock_movements.total_cost',
                'stock_movements.balance_after',
                'stock_movements.notes',
            ])
            ->when($filters['product_variant_id'], fn ($q, $id) => $q->where('stock_movements.product_variant_id', $id))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('stock_movements.warehouse_id', $id))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('stock_movements.occurred_at', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('stock_movements.occurred_at', '<=', $date));

        // occurred_at is not unique to the millisecond on a bulk import, so id
        // breaks the tie without changing the chronological story.
        return $query->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.id')
            ->paginate($perPage);
    }

    /**
     * The high-volume movement listing, filtered on the table's indexes.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function stockMovements(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->join('units', 'units.id', '=', 'stock_movements.unit_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.created_by')
            ->where($this->tenancy($user, $filters['company_id'], 'stock_movements.company_id'))
            ->select([
                'stock_movements.id',
                'stock_movements.occurred_at',
                'stock_movements.movement_type',
                'stock_movements.reference_type',
                'stock_movements.reference_id',
                'stock_movements.product_id',
                'products.sku as product_sku',
                'products.name as product_name',
                'stock_movements.product_variant_id',
                'product_variants.sku as variant_sku',
                'product_variants.name as variant_name',
                'stock_movements.warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'stock_movements.location_id',
                'stock_movements.unit_id',
                'units.code as unit_code',
                'stock_movements.quantity',
                'stock_movements.balance_after',
                'stock_movements.unit_cost',
                'stock_movements.total_cost',
                'stock_movements.created_by',
                'users.name as creator_name',
                'stock_movements.notes',
            ])
            ->when($filters['product_id'], fn ($q, $id) => $q->where('stock_movements.product_id', $id))
            ->when($filters['product_variant_id'], fn ($q, $id) => $q->where('stock_movements.product_variant_id', $id))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('stock_movements.warehouse_id', $id))
            ->when($filters['movement_type'], fn ($q, $type) => $q->where('stock_movements.movement_type', $type))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('stock_movements.occurred_at', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('stock_movements.occurred_at', '<=', $date))
            ->when($filters['reference_type'], fn ($q, $type) => $q->where('stock_movements.reference_type', $type))
            ->when($filters['reference_id'], fn ($q, $id) => $q->where('stock_movements.reference_id', $id))
            ->when($filters['search'], fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")));

        return $query->orderBy('stock_movements.occurred_at', $filters['direction'] === 'asc' ? 'asc' : 'desc')
            ->orderBy('stock_movements.id')
            ->paginate($perPage);
    }

    /**
     * Inventory-tracked products whose available quantity has reached the
     * reorder point.
     *
     * Balances are summed per product, warehouse, variant and unit, because a
     * product tracked in two units is two replenishment rows: the units are not
     * commensurable, so adding them would invent a quantity. A product with no
     * balance row at all is reported at zero, which is its real available
     * quantity, so an unstocked product at a positive reorder point is flagged.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function lowStock(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $balances = DB::table('stock_balances')
            ->select(
                'product_id',
                'product_variant_id',
                'warehouse_id',
                'unit_id',
                DB::raw('coalesce(sum(on_hand), 0) as on_hand'),
                DB::raw('coalesce(sum(reserved), 0) as reserved')
            )
            ->whereIn('company_id', $user->companies()->select('companies.id'))
            ->when($filters['company_id'], fn ($q, $id) => $q->where('company_id', $id))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('product_id', 'product_variant_id', 'warehouse_id', 'unit_id');

        $query = DB::table('products')
            ->leftJoinSub($balances, 'balances', 'balances.product_id', '=', 'products.id')
            ->leftJoin('units', 'units.id', '=', DB::raw('coalesce(balances.unit_id, products.default_unit_id)'))
            ->leftJoin('warehouses', 'warehouses.id', '=', 'balances.warehouse_id')
            ->select([
                'products.id as product_id',
                'products.company_id',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.category_id',
                'products.brand_id',
                'products.reorder_point',
                'products.minimum_stock',
                'products.reorder_quantity',
                DB::raw('coalesce(balances.unit_id, products.default_unit_id) as unit_id'),
                'units.code as unit_code',
                'warehouses.id as warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                DB::raw('coalesce(balances.on_hand, 0) as on_hand'),
                DB::raw('coalesce(balances.reserved, 0) as reserved'),
            ])
            ->whereIn('products.company_id', $user->companies()->select('companies.id'))
            ->when($filters['company_id'], fn ($q, $id) => $q->where('products.company_id', $id))
            ->where('products.track_inventory', true)
            ->whereRaw('coalesce(balances.on_hand, 0) - coalesce(balances.reserved, 0) <= products.reorder_point')
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('balances.warehouse_id', $id))
            ->when($filters['category_id'], fn ($q, $id) => $q->where('products.category_id', $id))
            ->when($filters['brand_id'], fn ($q, $id) => $q->where('products.brand_id', $id))
            ->when($filters['product_id'], fn ($q, $id) => $q->where('products.id', $id))
            ->when($filters['search'], fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")))
            ->orderByRaw('products.name asc, warehouses.name asc');

        return $query->paginate($perPage);
    }

    /**
     * On hand valued at the weighted average cost, with a subtotal per warehouse
     * and a grand total.
     *
     * The database only filters and groups; every multiplication and running
     * total is DecimalMath, so the report cannot disagree with the ledger's own
     * cost basis. Rows are grouped at the balance grain, which is unique per
     * product, variant, unit and location, so a row's average cost is that one
     * balance's own cost basis.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator<int, object>, subtotals: array<int, string>, total: string}
     */
    public function stockValuation(User $user, array $filters, int $perPage): array
    {
        $balances = function () use ($user, $filters): Builder {
            return StockBalance::query()
                ->join('products', 'products.id', '=', 'stock_balances.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
                ->join('units', 'units.id', '=', 'stock_balances.unit_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_balances.product_variant_id')
                ->where($this->tenancy($user, $filters['company_id'], 'stock_balances.company_id'))
                ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('stock_balances.warehouse_id', $id))
                ->when($filters['location_id'], fn ($q, $id) => $q->where('stock_balances.location_id', $id))
                ->when($filters['product_id'], fn ($q, $id) => $q->where('stock_balances.product_id', $id))
                ->when($filters['category_id'], fn ($q, $id) => $q->where('products.category_id', $id))
                ->when($filters['brand_id'], fn ($q, $id) => $q->where('products.brand_id', $id))
                ->when($filters['search'], fn ($q, $search) => $q->where(fn ($q) => $q
                    ->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%")
                    ->orWhere('product_variants.sku', 'like', "%{$search}%")));
        };

        $rows = $balances()
            ->select([
                'stock_balances.product_id',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.category_id',
                'products.brand_id',
                'stock_balances.product_variant_id',
                'product_variants.sku as variant_sku',
                'product_variants.name as variant_name',
                'stock_balances.warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'stock_balances.location_id',
                'stock_balances.unit_id',
                'units.code as unit_code',
                DB::raw('coalesce(sum(stock_balances.on_hand), 0) as on_hand'),
                DB::raw('coalesce(max(stock_balances.average_cost), 0) as average_cost'),
                DB::raw('coalesce(max(stock_balances.last_cost), 0) as last_cost'),
            ])
            ->groupBy(
                'stock_balances.product_id',
                'stock_balances.product_variant_id',
                'stock_balances.warehouse_id',
                'stock_balances.location_id',
                'stock_balances.unit_id'
            )
            ->orderByRaw('warehouses.name asc, products.name asc')
            ->paginate($perPage);

        // A row's value is its own cost basis times its own quantity; the
        // totals roll the same per-row products up in bcmath.
        $rows->through(function (object $row): object {
            $row->on_hand_value = DecimalMath::mul((string) $row->on_hand, (string) $row->average_cost);

            return $row;
        });

        $priced = $balances()
            ->select([
                'stock_balances.warehouse_id',
                'stock_balances.on_hand',
                'stock_balances.average_cost',
            ])
            ->get();

        $subtotals = [];

        foreach ($priced as $row) {
            $warehouseId = (int) $row->warehouse_id;
            $subtotals[$warehouseId] = DecimalMath::add(
                $subtotals[$warehouseId] ?? '0',
                DecimalMath::mul((string) $row->on_hand, (string) $row->average_cost)
            );
        }

        return [
            'rows' => $rows,
            'subtotals' => $subtotals,
            'total' => array_reduce(
                $subtotals,
                static fn (string $carry, string $value): string => DecimalMath::add($carry, $value),
                '0'
            ),
        ];
    }

    /**
     * Stock opname documents with a variance summary of system versus counted.
     *
     * The sums are DecimalMath over each document's own lines rather than a SQL
     * aggregate, so a variance never accumulates float drift across lines.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, StockOpname>
     */
    public function stockOpnames(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = StockOpname::query()
            ->visibleTo($user)
            ->when($filters['company_id'], fn ($q, $id) => $q->where('stock_opnames.company_id', $id))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('stock_opnames.warehouse_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('stock_opnames.status', $status))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('stock_opnames.opname_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('stock_opnames.opname_date', '<=', $date))
            ->when($filters['search'], fn ($q, $search) => $q->where('stock_opnames.number', 'like', "%{$search}%"))
            ->withCount('items')
            ->with([
                'countedBy:id,name',
                'items' => fn ($q) => $q->select([
                    'stock_opname_id',
                    'system_quantity',
                    'counted_quantity',
                ]),
            ])
            ->orderByRaw('stock_opnames.opname_date desc, stock_opnames.id desc');

        return $query->paginate($perPage)
            ->through(function (StockOpname $opname): StockOpname {
                $system = '0';
                $counted = '0';

                foreach ($opname->items as $item) {
                    $system = DecimalMath::add($system, (string) $item->system_quantity, 6);
                    $counted = DecimalMath::add($counted, (string) $item->counted_quantity, 6);
                }

                $opname->counted_by_name = $opname->countedBy?->name;
                $opname->system_quantity = $system;
                $opname->counted_quantity = $counted;
                // Sign follows the ledger: counted less system, so a count
                // above stock is a positive variance.
                $opname->variance_quantity = DecimalMath::sub($counted, $system, 6);

                return $opname;
            });
    }

    /**
     * Warehouse transfer documents with their status, item counts and quantity
     * totals.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WarehouseTransfer>
     */
    public function warehouseTransfers(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = WarehouseTransfer::query()
            ->visibleTo($user)
            ->when($filters['company_id'], fn ($q, $id) => $q->where('warehouse_transfers.company_id', $id))
            ->when($filters['from_warehouse_id'], fn ($q, $id) => $q->where('warehouse_transfers.from_warehouse_id', $id))
            ->when($filters['to_warehouse_id'], fn ($q, $id) => $q->where('warehouse_transfers.to_warehouse_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('warehouse_transfers.status', $status))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('warehouse_transfers.transfer_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('warehouse_transfers.transfer_date', '<=', $date))
            ->when($filters['search'], fn ($q, $search) => $q->where('warehouse_transfers.number', 'like', "%{$search}%"))
            ->withCount('items')
            ->with([
                'requestedBy:id,name',
                'items' => fn ($q) => $q->select([
                    'warehouse_transfer_id',
                    'quantity',
                    'quantity_received',
                ]),
            ])
            ->orderByRaw('warehouse_transfers.transfer_date desc, warehouse_transfers.id desc');

        return $query->paginate($perPage)
            ->through(function (WarehouseTransfer $transfer): WarehouseTransfer {
                $quantity = '0';
                $received = '0';

                foreach ($transfer->items as $item) {
                    $quantity = DecimalMath::add($quantity, (string) $item->quantity, 6);
                    $received = DecimalMath::add($received, (string) $item->quantity_received, 6);
                }

                $transfer->requested_by_name = $transfer->requestedBy?->name;
                $transfer->total_quantity = $quantity;
                $transfer->total_received = $received;

                return $transfer;
            });
    }

    /**
     * The tenancy predicate every joined report spells out: the user's own
     * companies, then the resolved company on top.
     *
     * A company the user does not belong to narrows the first condition to
     * nothing, so it can never widen a result. It mirrors scopeVisibleTo with a
     * qualified column, which the joined reports need because an unqualified
     * company_id is ambiguous across the label tables.
     *
     * @return \Closure(Builder): void
     */
    private function tenancy(User $user, ?int $companyId, string $column): \Closure
    {
        return fn (Builder $q) => $q
            ->whereIn($column, $user->companies()->select('companies.id'))
            ->when($companyId, fn ($q) => $q->where($column, $companyId));
    }
}
