<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product analytics foundation (spec §35).
 *
 * The per-product read across stock, cost, price and purchasing history. The
 * spec deliberately defers the classifications built on top of it - fast
 * moving, slow moving, dead stock and profitability - so this service carries
 * the raw signals those will consume: stock, cost, margin, last purchase and
 * movement recency. Everything is aggregated in SQL for the products on one
 * page, never one query per product.
 */
final class ProductAnalyticsService
{
    public function __construct(protected BusinessContext $context) {}

    /**
     * @param  array{company_id: ?int}  $filters
     * @return array{page: LengthAwarePaginator}
     */
    public function analytics(User $user, array $filters, int $perPage): array
    {
        $products = Product::query()
            ->visibleTo($user)
            ->where('company_id', $filters['company_id'])
            ->orderBy('name')
            ->paginate($perPage);

        $ids = $products->getCollection()->pluck('id')->all();

        // The aggregates are resolved once for the page, not per product, so
        // the report cost is a fixed set of queries whatever the page size.
        $aggregates = $this->aggregates($ids, $filters);

        $products->setCollection(
            $products->getCollection()
                ->map(fn (Product $product) => $this->toRow($product, $aggregates))
                ->values()
        );

        return ['page' => $products];
    }

    /**
     * One bounded pass of grouped queries for the products on the page.
     *
     * @param  array{company_id: ?int}  $filters
     * @return array{
     *     stock: array<int, object>,
     *     cost: array<int, object>,
     *     movements: array<int, object>,
     *     last_purchase: array<int, object>,
     *     suppliers: array<int, string>
     * }
     */
    private function aggregates(array $ids, array $filters): array
    {
        if ($ids === []) {
            return ['stock' => [], 'cost' => [], 'movements' => [], 'last_purchase' => [], 'suppliers' => []];
        }

        // On hand is summed across every warehouse, location and unit the
        // product is tracked at inside the company.
        $stock = DB::table('stock_balances')
            ->where('company_id', $filters['company_id'])
            ->whereIn('product_id', $ids)
            ->select('product_id', DB::raw('coalesce(sum(on_hand), 0) as total_stock'))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // A product can be carried in several warehouses at several costs, so
        // the balance holding the most stock is treated as the current cost
        // basis. Units are not converted between balance rows, and a product
        // with no balance at all falls back to its catalog cost_price.
        $cost = DB::query()
            ->fromSub(function (Builder $query) use ($ids, $filters): void {
                $query->from('stock_balances')
                    ->select('product_id', 'average_cost', 'on_hand', 'last_movement_at')
                    ->selectRaw(
                        'row_number() over ('
                        .'partition by product_id '
                        .'order by on_hand desc, (last_movement_at is null), last_movement_at desc'
                        .') as row_number'
                    )
                    ->where('company_id', $filters['company_id'])
                    ->whereIn('product_id', $ids);
            }, 'ranked_balances')
            ->where('row_number', 1)
            ->select('product_id', 'average_cost')
            ->get()
            ->keyBy('product_id');

        // The ledger is the source of truth for both how often a product has
        // moved and when it last moved, so both come from one grouped query.
        $movements = DB::table('stock_movements')
            ->where('company_id', $filters['company_id'])
            ->whereIn('product_id', $ids)
            ->select(
                'product_id',
                DB::raw('count(*) as movement_count'),
                DB::raw('max(occurred_at) as last_movement_at'),
            )
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // The most recent posted receipt holding the product. Ranking once per
        // product over the receipt lines keeps this a single query instead of a
        // lookup per product, and the quantity window sums every line of that
        // receipt for the product even when it arrives in more than one unit.
        $lastPurchase = DB::query()
            ->fromSub(function (Builder $query) use ($ids, $filters): void {
                $query->from('goods_receipt_items as gri')
                    ->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
                    ->select(
                        'gri.product_id',
                        'gr.receipt_date',
                        'gr.supplier_id',
                    )
                    ->selectRaw(
                        'sum(gri.quantity_received) over (partition by gri.product_id, gr.id) as quantity'
                    )
                    ->selectRaw(
                        'row_number() over ('
                        .'partition by gri.product_id '
                        .'order by gr.receipt_date desc, gr.id desc, gri.id desc'
                        .') as row_number'
                    )
                    ->where('gr.company_id', $filters['company_id'])
                    ->whereIn('gri.product_id', $ids)
                    ->where('gr.status', GoodsReceiptStatus::Posted->value)
                    ->whereNull('gr.deleted_at');
            }, 'last_purchase')
            ->where('row_number', 1)
            ->select('product_id', 'receipt_date', 'supplier_id', 'quantity')
            ->get()
            ->keyBy('product_id');

        $supplierIds = array_values(array_filter($lastPurchase->pluck('supplier_id')->all()));

        return [
            'stock' => $stock->all(),
            'cost' => $cost->all(),
            'movements' => $movements->all(),
            'last_purchase' => $lastPurchase->all(),
            'suppliers' => Supplier::query()
                ->whereIn('id', $supplierIds)
                ->pluck('name', 'id')
                ->all(),
        ];
    }

    /**
     * Assemble one product's analytics row.
     *
     * @param  array{stock: array<int, object>, cost: array<int, object>, movements: array<int, object>, last_purchase: array<int, object>, suppliers: array<int, string>}  $aggregates
     * @return array<string, mixed>
     */
    private function toRow(Product $product, array $aggregates): array
    {
        $stock = $aggregates['stock'][$product->id] ?? null;
        $cost = $aggregates['cost'][$product->id] ?? null;
        $movements = $aggregates['movements'][$product->id] ?? null;
        $lastPurchase = $aggregates['last_purchase'][$product->id] ?? null;

        // cost_price is the catalog fallback when the product has never been
        // received into a warehouse, so nothing is ever reported as free.
        $currentCost = $cost?->average_cost ?? (string) $product->cost_price;
        $sellingPrice = (string) $product->selling_price;

        $lastMovementAt = $movements?->last_movement_at;

        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'is_active' => (bool) $product->is_active,

            'total_stock' => $this->quantity($stock?->total_stock),
            'current_cost' => $this->money($currentCost),
            'current_price' => $this->money($sellingPrice),
            'estimated_margin' => $this->money(DecimalMath::margin($sellingPrice, $currentCost)),
            'estimated_margin_percent' => DecimalMath::marginPercent($sellingPrice, $currentCost),

            'last_purchase_date' => $lastPurchase?->receipt_date
                ? Carbon::parse($lastPurchase->receipt_date)->toDateString()
                : null,
            'last_purchase_quantity' => $lastPurchase ? $this->quantity($lastPurchase->quantity) : null,
            'last_supplier_id' => $lastPurchase?->supplier_id,
            'last_supplier_name' => $lastPurchase?->supplier_id
                ? ($aggregates['suppliers'][$lastPurchase->supplier_id] ?? null)
                : null,

            // Foundation for the future fast/slow/dead-stock classifications:
            // a turnover ratio and a days-since-last-movement field can be
            // derived from these two without restructuring this row.
            'movement_count' => (int) ($movements?->movement_count ?? 0),
            'last_movement_at' => $lastMovementAt,
            'days_since_last_movement' => $lastMovementAt
                ? (int) Carbon::parse($lastMovementAt)->startOfDay()->diffInDays(Carbon::today())
                : null,
        ];
    }

    /**
     * Money is emitted as an exact 4-digit decimal string, matching the column
     * scale, so a figure never degrades through float rounding on its way out.
     */
    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    /**
     * Quantities keep the 6-digit scale the ledger stores them at.
     */
    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 6);
    }
}
