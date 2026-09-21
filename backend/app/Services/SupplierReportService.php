<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReturnStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier reporting (spec §34).
 *
 * Every figure is aggregated live from the purchasing documents. Nothing is
 * stored, so a report can never disagree with the ledger behind it, and a
 * supplier with no activity reports zeros rather than disappearing (§51).
 */
final class SupplierReportService
{
    /**
     * How many products make up a supplier's "top products" list.
     */
    private const int TOP_PRODUCTS = 5;

    /**
     * Purchase orders that still represent a commitment to a supplier.
     *
     * A draft is a working copy rather than a transaction, a fully received
     * order has nothing left to come, and a closed or cancelled order is shut.
     * A partially received order reports its whole grand_total: it is the
     * committed value until the order closes, and no payment engine exists in
     * Phase 2 to offset what has already been received against it.
     */
    private const array OPEN_ORDER_STATUSES = [
        PurchaseOrderStatus::Submitted->value,
        PurchaseOrderStatus::Approved->value,
        PurchaseOrderStatus::Sent->value,
        PurchaseOrderStatus::PartiallyReceived->value,
    ];

    public function __construct(protected BusinessContext $context) {}

    /**
     * Paginated per-supplier purchase summary.
     *
     * The page of suppliers is resolved first, then the aggregates are computed
     * for exactly those suppliers: four grouped queries per page, never one
     * query per row.
     *
     * @param  array{company_id: ?int, date_from: ?string, date_to: ?string, warehouse_id: ?int, branch_id: ?int, supplier_id: ?int}  $filters
     * @return array{page: LengthAwarePaginator}
     */
    public function summary(User $user, array $filters, int $perPage): array
    {
        $suppliers = $this->supplierQuery($user, $filters)
            ->orderBy('name')
            ->paginate($perPage);

        $aggregates = $this->aggregates($suppliers->getCollection(), $filters);

        $suppliers->setCollection(
            $suppliers->getCollection()
                ->map(fn (Supplier $supplier) => $this->toRow($supplier, $aggregates))
                ->values()
        );

        return ['page' => $suppliers];
    }

    /**
     * One supplier's summary plus its document history, in three paginated
     * sections sharing the same filters as the summary.
     *
     * @param  array{company_id: ?int, date_from: ?string, date_to: ?string, warehouse_id: ?int, branch_id: ?int, supplier_id: ?int}  $filters
     * @return array{
     *     supplier: Supplier,
     *     summary: array<string, mixed>,
     *     orders: LengthAwarePaginator,
     *     receipts: LengthAwarePaginator,
     *     returns: LengthAwarePaginator
     * }
     */
    public function detail(User $user, int $supplierId, array $filters, int $perPage, int $page): array
    {
        $supplier = $this->supplierQuery($user, $filters)
            ->where('id', $supplierId)
            ->firstOrFail();

        $summary = $this->toRow($supplier, $this->aggregates(new Collection([$supplier]), $filters));

        $orders = PurchaseOrder::query()
            ->where('company_id', $filters['company_id'])
            ->where('supplier_id', $supplier->id)
            ->when($filters['date_from'], fn ($q, $date) => $q->where('order_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('order_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->with(['warehouse:id,company_id,code,name', 'items:id,purchase_order_id'])
            ->latest('order_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $receipts = GoodsReceipt::query()
            ->where('company_id', $filters['company_id'])
            ->where('supplier_id', $supplier->id)
            ->where('status', GoodsReceiptStatus::Posted->value)
            ->when($filters['date_from'], fn ($q, $date) => $q->where('receipt_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('receipt_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->with(['warehouse:id,company_id,code,name', 'purchaseOrder:id,number,status', 'items:id,goods_receipt_id'])
            ->latest('receipt_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $returns = PurchaseReturn::query()
            ->where('company_id', $filters['company_id'])
            ->where('supplier_id', $supplier->id)
            ->where('status', PurchaseReturnStatus::Posted->value)
            ->when($filters['date_from'], fn ($q, $date) => $q->where('return_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('return_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->with(['warehouse:id,company_id,code,name', 'items:id,purchase_return_id'])
            ->latest('return_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $this->attachReceiptTotals($receipts);

        return [
            'supplier' => $supplier,
            'summary' => $summary,
            'orders' => $orders,
            'receipts' => $receipts,
            'returns' => $returns,
        ];
    }

    /**
     * Supplier list a report may read: the user's companies narrowed to the
     * resolved company. A company the user cannot reach never yields a row,
     * so an unreachable company_id is an empty report rather than a leak.
     */
    private function supplierQuery(User $user, array $filters): Builder
    {
        return Supplier::query()
            ->visibleTo($user)
            ->where('company_id', $filters['company_id'])
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('id', $id));
    }

    /**
     * Every supplementary figure for the suppliers given, in a bounded set of
     * grouped queries rather than a query per supplier.
     *
     * @return array{receipts: array<int, object>, outstanding: array<int, object>, top_products: array<int, array>, trend: array<int, array>}
     */
    private function aggregates(Collection $suppliers, array $filters): array
    {
        $ids = $suppliers->pluck('id')->all();

        if ($ids === []) {
            return ['receipts' => [], 'outstanding' => [], 'top_products' => [], 'trend' => []];
        }

        // The authoritative source of "what was bought" is the posted goods
        // receipt, not the purchase order: an order is a commitment whose
        // price and quantity both move when the goods actually land, and the
        // receipt is the record of what was received and at what cost. A
        // receipt carries no total column, so its value is derived from its
        // lines, and the left join keeps an itemless receipt in the count.
        $receipts = DB::table('goods_receipts as gr')
            ->leftJoin('goods_receipt_items as gri', 'gri.goods_receipt_id', '=', 'gr.id')
            ->where('gr.company_id', $filters['company_id'])
            ->whereIn('gr.supplier_id', $ids)
            ->where('gr.status', GoodsReceiptStatus::Posted->value)
            ->whereNull('gr.deleted_at')
            ->when($filters['date_from'], fn ($q, $date) => $q->where('gr.receipt_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('gr.receipt_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('gr.warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('gr.branch_id', $id))
            ->select(
                'gr.supplier_id',
                DB::raw('count(distinct gr.id) as purchase_count'),
                DB::raw('coalesce(sum(gri.quantity_received * gri.unit_cost), 0) as total_purchase'),
                DB::raw('max(gr.receipt_date) as last_purchase_date'),
            )
            ->groupBy('gr.supplier_id')
            ->get()
            ->keyBy('supplier_id');

        // Outstanding is a snapshot of open orders, so it is not narrowed by
        // the date range the way the receipt figures are.
        $outstanding = DB::table('purchase_orders')
            ->where('company_id', $filters['company_id'])
            ->whereIn('supplier_id', $ids)
            ->whereIn('status', self::OPEN_ORDER_STATUSES)
            ->whereNull('deleted_at')
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->select('supplier_id', DB::raw('coalesce(sum(grand_total), 0) as outstanding'))
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $topProducts = DB::table('goods_receipts as gr')
            ->join('goods_receipt_items as gri', 'gri.goods_receipt_id', '=', 'gr.id')
            ->join('products as p', 'p.id', '=', 'gri.product_id')
            ->where('gr.company_id', $filters['company_id'])
            ->whereIn('gr.supplier_id', $ids)
            ->where('gr.status', GoodsReceiptStatus::Posted->value)
            ->whereNull('gr.deleted_at')
            ->when($filters['date_from'], fn ($q, $date) => $q->where('gr.receipt_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('gr.receipt_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('gr.warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('gr.branch_id', $id))
            ->select(
                'gr.supplier_id',
                'gri.product_id',
                'p.sku',
                'p.name',
                DB::raw('coalesce(sum(gri.quantity_received), 0) as quantity'),
                DB::raw('coalesce(sum(gri.quantity_received * gri.unit_cost), 0) as total'),
            )
            ->groupBy('gr.supplier_id', 'gri.product_id', 'p.sku', 'p.name')
            ->orderByRaw('quantity DESC, gri.product_id ASC')
            ->get();

        $trend = DB::table('goods_receipts as gr')
            ->leftJoin('goods_receipt_items as gri', 'gri.goods_receipt_id', '=', 'gr.id')
            ->where('gr.company_id', $filters['company_id'])
            ->whereIn('gr.supplier_id', $ids)
            ->where('gr.status', GoodsReceiptStatus::Posted->value)
            ->whereNull('gr.deleted_at')
            ->when($filters['date_from'], fn ($q, $date) => $q->where('gr.receipt_date', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->where('gr.receipt_date', '<=', $date))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('gr.warehouse_id', $id))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('gr.branch_id', $id))
            ->select(
                'gr.supplier_id',
                DB::raw($this->monthExpression('gr.receipt_date').' as month'),
                DB::raw('coalesce(sum(gri.quantity_received * gri.unit_cost), 0) as total'),
            )
            ->groupBy('gr.supplier_id', 'month')
            ->orderByRaw('month ASC')
            ->get();

        return [
            'receipts' => $receipts->all(),
            'outstanding' => $outstanding->all(),
            'top_products' => $this->rankTopProducts($topProducts),
            'trend' => $trend->groupBy('supplier_id')
                ->map(fn (\Illuminate\Support\Collection $months) => $months->map(fn ($row) => [
                    'month' => $row->month,
                    'total' => $this->money($row->total),
                ])->values()->all())
                ->all(),
        ];
    }

    /**
     * Keep only the leading products per supplier. The rows arrive ordered by
     * quantity, so this is a slice per supplier rather than another query.
     *
     * @return array<int, array>
     */
    private function rankTopProducts(\Illuminate\Support\Collection $rows): array
    {
        $ranked = [];

        foreach ($rows as $row) {
            $ranked[$row->supplier_id][] = [
                'product_id' => $row->product_id,
                'sku' => $row->sku,
                'name' => $row->name,
                'quantity' => $this->quantity($row->quantity),
                'total' => $this->money($row->total),
            ];
        }

        return array_map(fn (array $products) => array_slice($products, 0, self::TOP_PRODUCTS), $ranked);
    }

    /**
     * Merge one summary row for a supplier from the grouped aggregates.
     *
     * @param  array{receipts: array<int, object>, outstanding: array<int, object>, top_products: array<int, array>, trend: array<int, array>}  $aggregates
     * @return array<string, mixed>
     */
    private function toRow(Supplier $supplier, array $aggregates): array
    {
        $receipts = $aggregates['receipts'][$supplier->id] ?? null;

        // MySQL answers max() on a DATE column with a full datetime string, so
        // the value is trimmed back to the date the column actually holds.
        $lastPurchase = $receipts?->last_purchase_date
            ? Carbon::parse($receipts->last_purchase_date)->toDateString()
            : null;

        return [
            'supplier_id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->name,
            'total_purchase' => $this->money($receipts?->total_purchase),
            'purchase_count' => (int) ($receipts?->purchase_count ?? 0),
            'outstanding' => $this->money(($aggregates['outstanding'][$supplier->id] ?? null)?->outstanding),
            'last_purchase_date' => $lastPurchase,
            'top_products' => $aggregates['top_products'][$supplier->id] ?? [],
            'purchase_trend' => $aggregates['trend'][$supplier->id] ?? [],
        ];
    }

    /**
     * Value the receipts on a page from their lines, then hand each receipt its
     * total as a transient attribute the report resource reads.
     */
    private function attachReceiptTotals(LengthAwarePaginator $receipts): void
    {
        $ids = $receipts->getCollection()->pluck('id')->all();

        $totals = DB::table('goods_receipt_items')
            ->whereIn('goods_receipt_id', $ids)
            ->select('goods_receipt_id', DB::raw('coalesce(sum(quantity_received * unit_cost), 0) as received_total'))
            ->groupBy('goods_receipt_id')
            ->pluck('received_total', 'goods_receipt_id');

        $receipts->getCollection()->each(
            fn (GoodsReceipt $receipt) => $receipt->setAttribute('received_total', $this->money($totals[$receipt->id] ?? null))
        );
    }

    /**
     * A YYYY-MM bucket in the dialect of the connected engine: SQLite has no
     * DATE_FORMAT and MySQL has no strftime.
     */
    private function monthExpression(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Money is emitted as an exact 4-digit decimal string, matching the column
     * scale. A product of a 6-digit quantity and a 4-digit cost carries more
     * digits than money is stored at, so it is rounded half-up back to scale
     * rather than truncated, which would understate every total.
     */
    private function money(mixed $value): string
    {
        $scaled = bcmul((string) ($value ?? '0'), '10000', 1);

        return bcdiv(bcadd($scaled, '0.5', 1), '10000', 4);
    }

    /**
     * Quantities keep the 6-digit scale the ledger stores them at.
     */
    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 6);
    }
}
