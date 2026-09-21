<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation engine for the purchasing reports (spec §33).
 *
 * Every figure is summed in SQL over rows that really exist; nothing is stored,
 * cached or invented, and an empty filter window answers zero rather than a
 * demo figure (spec §51). Money never passes through a float: the columns are
 * DECIMAL(20,4) and the sums are normalised back to exact decimal strings on
 * the way out (spec §46).
 *
 * Header totals and line quantities are summed by separate grouped queries
 * rather than one join, so an order with five lines contributes its grand
 * total once and its quantity five times instead of the reverse.
 */
class PurchasingReportService
{
    private const ORDER_DIMENSIONS = ['supplier', 'branch', 'warehouse', 'status', 'month'];

    private const RETURN_DIMENSIONS = ['supplier', 'product', 'date'];

    private const NOT_OUTSTANDING = ['received', 'closed', 'cancelled'];

    private const ORDER_MONEY = 'coalesce(sum(purchase_orders.subtotal), 0) as total_gross, '
        .'coalesce(sum(purchase_orders.item_discount_total), 0) '
        .'+ coalesce(sum(purchase_orders.discount_total), 0) as total_discount, '
        .'coalesce(sum(purchase_orders.tax_total), 0) as total_tax, '
        .'coalesce(sum(purchase_orders.grand_total), 0) as total_grand_total';

    public function __construct(protected BusinessContext $context) {}

    /**
     * The filter window shared by every endpoint, resolved once per request.
     *
     * @return array<string, mixed>
     */
    public function filters(Request $request): array
    {
        $int = fn (string $key): ?int => $request->filled($key) ? $request->integer($key) : null;

        return [
            'company_id' => $int('company_id') ?: $this->context->companyId(),
            'start_date' => $request->filled('start_date') ? (string) $request->string('start_date') : null,
            'end_date' => $request->filled('end_date') ? (string) $request->string('end_date') : null,
            'supplier_id' => $int('supplier_id'),
            'warehouse_id' => $int('warehouse_id'),
            'branch_id' => $int('branch_id'),
            'product_id' => $int('product_id'),
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function summaryDimensions(): array
    {
        return [...self::ORDER_DIMENSIONS, 'product'];
    }

    /**
     * @return list<string>
     */
    public function returnDimensions(): array
    {
        return self::RETURN_DIMENSIONS;
    }

    /**
     * Whole-window totals over the purchase orders in scope.
     *
     * @return array<string, string|int>
     */
    public function summaryTotals(Request $request): array
    {
        $f = $this->filters($request);

        $orders = $this->orders($request, $f)
            ->selectRaw('count(*) as total_orders, '.self::ORDER_MONEY)
            ->first();

        $quantity = $this->items($request, $f)
            ->selectRaw('coalesce(sum(purchase_order_items.quantity), 0) as total_quantity')
            ->first();

        return [
            'total_orders' => (int) ($orders?->total_orders ?? 0),
            'total_quantity' => $this->quantity($quantity?->total_quantity),
            'total_gross' => $this->money($orders?->total_gross),
            'total_discount' => $this->money($orders?->total_discount),
            'total_tax' => $this->money($orders?->total_tax),
            'total_grand_total' => $this->money($orders?->total_grand_total),
        ];
    }

    /**
     * Summary totals broken down by the requested dimension.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summaryGroups(Request $request, string $groupBy): Collection
    {
        $f = $this->filters($request);

        if ($groupBy === 'product') {
            return $this->productRows($request, $f)
                ->map(fn (object $row) => [
                    'key' => (string) $row->product_id,
                    'label' => $row->product_name,
                    'order_count' => (int) $row->order_count,
                    // A product group cannot carry header shipping or a header
                    // discount, so its money columns are the line-derived ones.
                    'total_quantity' => $this->quantity($row->quantity_ordered),
                    'total_gross' => $this->money($row->gross_value),
                    'total_discount' => $this->money(DecimalMath::sub($this->money($row->gross_value), $this->money($row->net_value))),
                    'total_tax' => $this->money($row->tax_amount),
                    'total_grand_total' => $this->money($row->subtotal_value),
                ])
                ->values();
        }

        $dimension = $this->dimensionColumn($groupBy);

        $orders = $this->orders($request, $f)
            ->leftJoin('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'purchase_orders.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'purchase_orders.supplier_id')
            ->selectRaw("{$dimension} as key, count(*) as order_count, ".self::ORDER_MONEY.', '.$this->dimensionLabel($groupBy))
            ->groupByRaw($dimension)
            ->get();

        $quantity = $this->items($request, $f)
            // The dimension lives on the order, so the lines must be joined
            // back to it before they can be grouped by it.
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->selectRaw("{$dimension} as key, coalesce(sum(purchase_order_items.quantity), 0) as total_quantity")
            ->groupByRaw($dimension)
            ->get();

        return $orders
            ->map(fn (object $row) => [
                'key' => $row->key === null ? 'unassigned' : (string) $row->key,
                'label' => $this->groupLabel($groupBy, $row),
                'order_count' => (int) $row->order_count,
                'total_quantity' => $this->quantity($quantity->firstWhere('key', $row->key)?->total_quantity),
                'total_gross' => $this->money($row->total_gross),
                'total_discount' => $this->money($row->total_discount),
                'total_tax' => $this->money($row->total_tax),
                'total_grand_total' => $this->money($row->total_grand_total),
            ])
            ->values();
    }

    /**
     * Line-level rows for the detail report. The relations the resource renders
     * are eager-loaded so a page never emits one query per row.
     */
    public function detailQuery(Request $request): Builder
    {
        $f = $this->filters($request);

        return PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function ($q) use ($request, $f): void {
                // A line is visible when its order is: purchase_order_items has
                // no company column of its own to scope on.
                $q->visibleTo($request->user());
                $this->applyOrderScopes($q, $f);
            })
            ->when($f['product_id'], fn ($q, $id) => $q->where('product_id', $id))
            ->with([
                'product:id,company_id,sku,barcode,name',
                'unit:id,company_id,code,name',
                'purchaseOrder:id,company_id,branch_id,warehouse_id,supplier_id,number,order_date,status,grand_total',
                'purchaseOrder.supplier:id,company_id,supplier_code,name',
                'purchaseOrder.warehouse:id,company_id,code,name',
                'purchaseOrder.branch:id,company_id,code,name',
            ])
            ->when(
                $request->filled('sort') && in_array($request->string('sort')->toString(), ['quantity', 'unit_price', 'net_price', 'tax_amount', 'subtotal', 'created_at'], true),
                fn ($q) => $q->orderBy($request->string('sort')->toString(), $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->orderByDesc('purchase_order_id')->orderBy('id')
            );
    }

    /**
     * Per-supplier purchasing balance: what was ordered, what landed, what went
     * back and what remains.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bySupplier(Request $request): Collection
    {
        $rows = $this->byDimension($request, 'supplier');

        $codes = Supplier::query()
            ->whereIn('id', $rows->map(fn (array $row) => (int) $row['key'])->all())
            ->whereIn('company_id', $this->companyIds($request))
            ->pluck('supplier_code', 'id')
            ->all();

        return $rows->map(fn (array $row) => [
            'supplier_id' => (int) $row['key'],
            'supplier_code' => $codes[(int) $row['key']] ?? null,
            'supplier_name' => $row['label'],
            'order_count' => $row['order_count'],
            'return_count' => $row['return_count'],
            'total_purchased' => $row['total_purchased'],
            'total_received' => $row['total_received'],
            'total_return' => $row['total_return'],
            'net_purchased' => $row['net_purchased'],
        ]);
    }

    /**
     * Per-product purchasing: how much was asked for, how much arrived and what
     * the net cost basis is.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byProduct(Request $request): Collection
    {
        $f = $this->filters($request);

        return $this->productRows($request, $f)
            ->map(fn (object $row) => [
                'product_id' => (int) $row->product_id,
                'product_sku' => $row->product_sku,
                'product_name' => $row->product_name,
                'order_count' => (int) $row->order_count,
                'quantity_ordered' => $this->quantity($row->quantity_ordered),
                'quantity_received' => $this->quantity($row->quantity_received),
                'remaining_quantity' => DecimalMath::sub($this->quantity($row->quantity_ordered), $this->quantity($row->quantity_received), 6),
                'gross_value' => $this->money($row->gross_value),
                'net_value' => $this->money($row->net_value),
                'tax_amount' => $this->money($row->tax_amount),
                'average_unit_cost' => DecimalMath::div($this->money($row->net_value), $this->quantity($row->quantity_ordered)),
            ])
            ->values();
    }

    /**
     * Per-branch and per-warehouse purchasing balances.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byBranch(Request $request): Collection
    {
        return $this->byDimension($request, 'branch');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function byWarehouse(Request $request): Collection
    {
        return $this->byDimension($request, 'warehouse');
    }

    /**
     * Posted purchase returns in the window, with their totals.
     *
     * @return array<string, string|int>
     */
    public function returnTotals(Request $request): array
    {
        $f = $this->filters($request);

        $row = $this->returns($request, $f)
            ->leftJoin('purchase_return_items as pri', 'pri.purchase_return_id', '=', 'purchase_returns.id')
            ->selectRaw(
                'count(distinct purchase_returns.id) as total_returns, '
                .'coalesce(sum(purchase_returns.total_amount), 0) as total_amount, '
                .'coalesce(sum(pri.quantity), 0) as total_quantity'
            )
            ->first();

        return [
            'total_returns' => (int) ($row?->total_returns ?? 0),
            'total_quantity' => $this->quantity($row?->total_quantity),
            'total_amount' => $this->money($row?->total_amount),
        ];
    }

    /**
     * Posted returns grouped by supplier, product or return date. The item join
     * is fan-out free because every line belongs to exactly one return.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function returnGroups(Request $request, string $groupBy): Collection
    {
        $f = $this->filters($request);

        if ($groupBy === 'product') {
            return $this->returns($request, $f)
                ->join('purchase_return_items as pri', 'pri.purchase_return_id', '=', 'purchase_returns.id')
                ->join('products as p', 'p.id', '=', 'pri.product_id')
                ->selectRaw(
                    'pri.product_id as key, p.sku as product_sku, p.name as label, '
                    .'count(distinct purchase_returns.id) as return_count, '
                    .'coalesce(sum(pri.quantity), 0) as total_quantity, '
                    .'coalesce(sum(pri.total_amount), 0) as total_amount'
                )
                ->groupBy('pri.product_id', 'p.sku', 'p.name')
                ->orderBy('p.name')
                ->get()
                ->map(fn (object $row) => $this->returnRow($row))
                ->values();
        }

        $column = $groupBy === 'date'
            ? 'substr(purchase_returns.return_date, 1, 10)'
            : 'purchase_returns.supplier_id';

        $rows = $this->returns($request, $f)
            ->leftJoin('purchase_return_items as pri', 'pri.purchase_return_id', '=', 'purchase_returns.id')
            ->leftJoin('suppliers as s', 's.id', '=', 'purchase_returns.supplier_id')
            ->selectRaw(
                "{$column} as key, "
                .'count(distinct purchase_returns.id) as return_count, '
                .'coalesce(sum(pri.quantity), 0) as total_quantity, '
                .'coalesce(sum(pri.total_amount), 0) as total_amount, '
                .($groupBy === 'date'
                    ? 'substr(purchase_returns.return_date, 1, 10) as label'
                    : 'max(s.name) as label')
            )
            ->groupByRaw($column)
            ->orderByRaw($groupBy === 'date' ? 'purchase_returns.return_date' : 'max(s.name)')
            ->get();

        return $rows
            ->map(fn (object $row) => $this->returnRow($row))
            ->values();
    }

    /**
     * Purchase orders that still have something to receive: the actionable
     * buying list.
     *
     * An order qualifies while its status is open and at least one line is
     * short of its ordered quantity. Rows are grouped by order and product, so
     * a buyer reads one row per line still to buy. The values are valued at the
     * line unit prices, and the order's grand_total carries the header
     * discount, tax and shipping.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstanding(Request $request): Collection
    {
        $f = $this->filters($request);

        $rows = $this->items($request, $f)
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'po.warehouse_id')
            ->leftJoin('products as p', 'p.id', '=', 'purchase_order_items.product_id')
            ->whereNotIn('po.status', self::NOT_OUTSTANDING)
            ->selectRaw(
                'po.id as purchase_order_id, po.number as purchase_order_number, '
                .'po.order_date as order_date, po.status as status, po.grand_total as grand_total, '
                .'s.id as supplier_id, s.name as supplier_name, '
                .'w.id as warehouse_id, w.name as warehouse_name, '
                .'p.id as product_id, p.name as product_name, '
                .'coalesce(sum(purchase_order_items.quantity), 0) as quantity_ordered, '
                .'coalesce(sum(purchase_order_items.quantity_received), 0) as quantity_received, '
                .'coalesce(sum(purchase_order_items.quantity - purchase_order_items.quantity_received), 0) as remaining_quantity, '
                // Valued at the line unit prices: net_price is a line total, so
                // only the unit price can be multiplied by a partial quantity
                // without allocating a line discount across a fraction of it.
                .'coalesce(sum(purchase_order_items.quantity * purchase_order_items.unit_price), 0) as ordered_value, '
                .'coalesce(sum(purchase_order_items.quantity_received * purchase_order_items.unit_price), 0) as received_value, '
                .'coalesce(sum((purchase_order_items.quantity - purchase_order_items.quantity_received) * purchase_order_items.unit_price), 0) as remaining_value'
            )
            ->groupBy(
                'po.id', 'po.number', 'po.order_date', 'po.status', 'po.grand_total',
                's.id', 's.name', 'w.id', 'w.name', 'p.id', 'p.name'
            )
            ->havingRaw('sum(purchase_order_items.quantity - purchase_order_items.quantity_received) > 0')
            ->orderBy('po.order_date')
            ->get();

        return $rows->map(fn (object $row) => [
            'purchase_order_id' => (int) $row->purchase_order_id,
            'purchase_order_number' => $row->purchase_order_number,
            'order_date' => $row->order_date,
            'status' => $row->status,
            'grand_total' => $this->money($row->grand_total),
            'supplier_id' => $row->supplier_id !== null ? (int) $row->supplier_id : null,
            'supplier_name' => $row->supplier_name,
            'warehouse_id' => $row->warehouse_id !== null ? (int) $row->warehouse_id : null,
            'warehouse_name' => $row->warehouse_name,
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_name' => $row->product_name,
            'quantity_ordered' => $this->quantity($row->quantity_ordered),
            'quantity_received' => $this->quantity($row->quantity_received),
            'remaining_quantity' => $this->quantity($row->remaining_quantity),
            'ordered_value' => $this->money($row->ordered_value),
            'received_value' => $this->money($row->received_value),
            'remaining_value' => $this->money($row->remaining_value),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query builders
    |--------------------------------------------------------------------------
    */

    /**
     * Purchase orders visible to the user, narrowed to the resolved company and
     * the filter window.
     */
    private function orders(Request $request, array $f): QueryBuilder
    {
        return $this->applyOrderScopes(
            DB::table('purchase_orders')->whereIn('purchase_orders.company_id', $this->companyIds($request)),
            $f
        );
    }

    /**
     * Purchase order lines for orders the user may see. Every line joins to one
     * order, so summing over this set counts each line exactly once.
     */
    private function items(Request $request, array $f): QueryBuilder
    {
        return DB::table('purchase_order_items')
            ->whereIn('purchase_order_items.purchase_order_id', function ($q) use ($request, $f): void {
                $q->select('purchase_orders.id')
                    ->from('purchase_orders')
                    ->whereIn('purchase_orders.company_id', $this->companyIds($request));
                $this->applyOrderScopes($q, $f, 'purchase_orders');
            })
            ->when($f['product_id'], fn ($q, $id) => $q->where('purchase_order_items.product_id', $id));
    }

    /**
     * Posted goods receipts in the same window, valued at what was actually paid.
     */
    private function receipts(Request $request, array $f): QueryBuilder
    {
        return DB::table('goods_receipts')
            ->whereIn('goods_receipts.company_id', $this->companyIds($request))
            ->where('goods_receipts.status', 'posted')
            ->whereNull('goods_receipts.deleted_at')
            ->when($f['company_id'], fn ($q, $id) => $q->where('goods_receipts.company_id', $id))
            ->when($f['start_date'], fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($f['end_date'], fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d))
            ->when($f['supplier_id'], fn ($q, $id) => $q->where('goods_receipts.supplier_id', $id))
            ->when($f['warehouse_id'], fn ($q, $id) => $q->where('goods_receipts.warehouse_id', $id))
            ->when($f['branch_id'], fn ($q, $id) => $q->where('goods_receipts.branch_id', $id));
    }

    /**
     * Posted purchase returns in the same window.
     */
    private function returns(Request $request, array $f): QueryBuilder
    {
        return DB::table('purchase_returns')
            ->whereIn('purchase_returns.company_id', $this->companyIds($request))
            ->where('purchase_returns.status', 'posted')
            ->whereNull('purchase_returns.deleted_at')
            ->when($f['company_id'], fn ($q, $id) => $q->where('purchase_returns.company_id', $id))
            ->when($f['start_date'], fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '>=', $d))
            ->when($f['end_date'], fn ($q, $d) => $q->whereDate('purchase_returns.return_date', '<=', $d))
            ->when($f['supplier_id'], fn ($q, $id) => $q->where('purchase_returns.supplier_id', $id))
            ->when($f['warehouse_id'], fn ($q, $id) => $q->where('purchase_returns.warehouse_id', $id))
            ->when($f['branch_id'], fn ($q, $id) => $q->where('purchase_returns.branch_id', $id));
    }

    /**
     * Product-level purchasing rows, shared by the by-product report and the
     * product grouping of the summary.
     */
    private function productRows(Request $request, array $f): Collection
    {
        return $this->items($request, $f)
            ->join('products as p', 'p.id', '=', 'purchase_order_items.product_id')
            ->selectRaw(
                'purchase_order_items.product_id as product_id, '
                .'p.sku as product_sku, p.name as product_name, '
                .'count(distinct purchase_order_items.purchase_order_id) as order_count, '
                .'coalesce(sum(purchase_order_items.quantity), 0) as quantity_ordered, '
                .'coalesce(sum(purchase_order_items.quantity_received), 0) as quantity_received, '
                .'coalesce(sum(purchase_order_items.quantity * purchase_order_items.unit_price), 0) as gross_value, '
                .'coalesce(sum(purchase_order_items.net_price), 0) as net_value, '
                .'coalesce(sum(purchase_order_items.tax_amount), 0) as tax_amount, '
                .'coalesce(sum(purchase_order_items.subtotal), 0) as subtotal_value'
            )
            ->groupBy('purchase_order_items.product_id', 'p.sku', 'p.name')
            ->orderBy('p.name')
            ->get();
    }

    /**
     * Supplier, branch and warehouse balances built from the order, receipt and
     * return sums that share that dimension.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function byDimension(Request $request, string $dimension): Collection
    {
        $f = $this->filters($request);

        $ordered = $this->orders($request, $f)
            ->selectRaw(
                "purchase_orders.{$dimension}_id as dimension_id, count(*) as order_count, "
                .'coalesce(sum(purchase_orders.grand_total), 0) as total_purchased'
            )
            ->groupBy("purchase_orders.{$dimension}_id")
            ->get();

        $received = $this->receipts($request, $f)
            ->join('goods_receipt_items as gri', 'gri.goods_receipt_id', '=', 'goods_receipts.id')
            ->selectRaw(
                "goods_receipts.{$dimension}_id as dimension_id, "
                .'coalesce(sum(gri.quantity_received * gri.unit_cost), 0) as total_received'
            )
            ->groupBy("goods_receipts.{$dimension}_id")
            ->get();

        $returned = $this->returns($request, $f)
            ->selectRaw(
                "purchase_returns.{$dimension}_id as dimension_id, count(*) as return_count, "
                .'coalesce(sum(purchase_returns.total_amount), 0) as total_return'
            )
            ->groupBy("purchase_returns.{$dimension}_id")
            ->get();

        $names = $this->dimensionNames($request, $dimension, $ordered, $received, $returned);

        return $ordered
            ->map(function (object $row) use ($received, $returned, $names, $dimension) {
                $id = $row->dimension_id;
                $purchased = $this->money($row->total_purchased);
                $return = $this->money($returned->firstWhere('dimension_id', $id)?->total_return);

                return [
                    'key' => $id === null ? 'unassigned' : (string) $id,
                    'label' => isset($names[$id]) ? $names[$id] : ($dimension === 'supplier' ? 'Unassigned supplier' : 'Unassigned'),
                    'order_count' => (int) $row->order_count,
                    'return_count' => (int) ($returned->firstWhere('dimension_id', $id)?->return_count ?? 0),
                    'total_purchased' => $purchased,
                    'total_received' => $this->money($received->firstWhere('dimension_id', $id)?->total_received),
                    'total_return' => $return,
                    'net_purchased' => DecimalMath::sub($purchased, $return),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function companyIds(Request $request): array
    {
        return $request->user()?->companies()->pluck('companies.id')->all() ?? [];
    }

    /**
     * Apply the shared order filter window to a query scoped at purchase_orders.
     *
     * @param  array<string, mixed>  $f
     */
    private function applyOrderScopes(Builder|QueryBuilder $q, array $f, string $alias = 'purchase_orders'): Builder|QueryBuilder
    {
        return $q
            ->when($f['company_id'], fn ($q, $id) => $q->where("{$alias}.company_id", $id))
            ->when($f['start_date'], fn ($q, $d) => $q->whereDate("{$alias}.order_date", '>=', $d))
            ->when($f['end_date'], fn ($q, $d) => $q->whereDate("{$alias}.order_date", '<=', $d))
            ->when($f['supplier_id'], fn ($q, $id) => $q->where("{$alias}.supplier_id", $id))
            ->when($f['warehouse_id'], fn ($q, $id) => $q->where("{$alias}.warehouse_id", $id))
            ->when($f['branch_id'], fn ($q, $id) => $q->where("{$alias}.branch_id", $id))
            ->when($f['status'], fn ($q, $s) => $q->where("{$alias}.status", $s));
    }

    private function dimensionColumn(string $groupBy): string
    {
        return match ($groupBy) {
            'supplier' => 'purchase_orders.supplier_id',
            'branch' => 'purchase_orders.branch_id',
            'warehouse' => 'purchase_orders.warehouse_id',
            'status' => 'purchase_orders.status',
            default => 'substr(purchase_orders.order_date, 1, 7)',
        };
    }

    private function dimensionLabel(string $groupBy): string
    {
        return match ($groupBy) {
            'supplier' => 'max(s.name) as label',
            'branch' => 'max(b.name) as label',
            'warehouse' => 'max(w.name) as label',
            'status' => 'purchase_orders.status as label',
            default => 'substr(purchase_orders.order_date, 1, 7) as label',
        };
    }

    private function groupLabel(string $groupBy, object $row): string
    {
        if ($row->key === null) {
            return 'Unassigned';
        }

        return $row->label ?? (string) $row->key;
    }

    /**
     * Resolve the display names for the dimension ids that appear in the sums,
     * so a group never reports a foreign key as if it were a name.
     *
     * @return array<int, string>
     */
    private function dimensionNames(Request $request, string $dimension, Collection ...$sets): array
    {
        $ids = collect($sets)
            ->flatMap(fn (Collection $set) => $set->pluck('dimension_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        $model = $dimension === 'supplier' ? Supplier::class : ($dimension === 'branch' ? Branch::class : Warehouse::class);

        return $model::query()
            ->whereIn('id', $ids)
            ->whereIn('company_id', $this->companyIds($request))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function returnRow(object $row): array
    {
        return [
            'key' => $row->key === null ? 'unassigned' : (string) $row->key,
            'label' => $row->label ?? 'Unassigned',
            'product_sku' => $row->product_sku ?? null,
            'return_count' => (int) $row->return_count,
            'total_quantity' => $this->quantity($row->total_quantity),
            'total_amount' => $this->money($row->total_amount),
        ];
    }

    /**
     * Money leaves as an exact 4-digit decimal string, matching the column
     * scale, so a sum never degrades through float rounding.
     */
    private function money(mixed $value): string
    {
        return DecimalMath::add($value === null ? null : (string) $value, '0', 4);
    }

    private function quantity(mixed $value): string
    {
        return DecimalMath::add($value === null ? null : (string) $value, '0', 6);
    }
}
