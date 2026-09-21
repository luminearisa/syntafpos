<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SearchPosProductsRequest;
use App\Http\Resources\PosProductResource;
use App\Models\PosCart;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Services\PriceResolutionService;
use App\Support\BusinessContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The till's product read side: search for what to ring up, scan a code.
 *
 * This is deliberately not the Phase 2 product index. The grid answers a
 * different question — what may be sold, at the price this customer pays, with
 * what is on the shelf at this register's warehouse — and it must answer it on
 * every keystroke, so the queries are bounded to one page at a time.
 */
class PosProductController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BusinessContext $context,
        protected PriceResolutionService $prices
    ) {}

    /**
     * Debounced typeahead and category browsing.
     */
    public function search(SearchPosProductsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PosCart::class);

        $companyId = $this->context->companyId();

        if (! $companyId) {
            return $this->error('No active company context.', 403);
        }

        $validated = $request->validated();
        $barcode = trim((string) ($validated['barcode'] ?? ''));

        // A scan is an exact question, so it never falls through to the fuzzy
        // search: an operator needs one clear "found / not found", not a grid.
        if ($barcode !== '') {
            return $this->scan($companyId, $barcode, $request);
        }

        $warehouseId = $request->integer('warehouse_id') ?: $this->context->warehouseId();

        $products = $this->baseQuery($companyId)
            ->when(trim((string) ($validated['search'] ?? '')) !== '',
                fn (Builder $q) => $this->applySearch($q, trim((string) $validated['search'])))
            ->when($validated['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($validated['brand_id'] ?? null, fn (Builder $q, $id) => $q->where('brand_id', $id))
            ->with($this->eagerLoad($warehouseId))
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 24));

        $customerId = $this->cartCustomerId($request);

        return $this->success(
            $this->grid($products, $customerId, $warehouseId),
            'Success',
            200,
            $this->pageMeta($products)
        );
    }

    /**
     * One scanned code, one line to ring up.
     */
    private function scan(int $companyId, string $code, Request $request): JsonResponse
    {
        [$product, $variantId] = $this->resolveByCode($companyId, $code);

        if (! $product) {
            // Say what was scanned: a cashier reads this back to the customer.
            return $this->error("Barcode {$code} was not found.", 404);
        }

        if (! $product->is_sellable || ! $product->is_active) {
            return $this->error("Barcode {$code} belongs to {$product->name}, which cannot be sold.", 422);
        }

        $warehouseId = $request->integer('warehouse_id') ?: $this->context->warehouseId();

        $product->load($this->eagerLoad($warehouseId));

        $priced = $this->prices->resolve($product, $variantId, $this->cartCustomerId($request));
        $balances = $this->balancesFor($product, $variantId);

        return $this->success([
            'matched_code' => $code,
            'variant_id' => $variantId,
            'product' => (new PosProductResource(
                $product,
                $priced,
                PosProductResource::stockOf($balances)
            ))->resolve(),
            // The add-to-cart payload the till sends straight back to the cart.
            'add_to_cart' => array_filter([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity' => '1',
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * Sellable products of the active company, variants included.
     */
    private function baseQuery(int $companyId): Builder
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_sellable', true)
            ->where('is_active', true);
    }

    /**
     * One box for name, SKU, barcode, category and brand.
     *
     * Variant codes match exactly rather than by prefix: they are scanned or
     * typed whole, and a LIKE over them would defeat the unique index.
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $q) use ($like, $search) {
            $q->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $like))
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like))
                ->orWhereHas('variants', function (Builder $v) use ($search) {
                    $v->where('sku', $search)->orWhere('barcode', $search);
                });
        });
    }

    /**
     * Relations a grid row needs, with stock narrowed to the till's warehouse.
     *
     * @return list<string|\Closure>
     */
    private function eagerLoad(?int $warehouseId): array
    {
        $balances = fn ($query) => $warehouseId ? $query->where('warehouse_id', $warehouseId) : $query;

        return [
            'category:id,name',
            'brand:id,name',
            'defaultUnit:id,name,code',
            'variants' => fn ($query) => $query->where('is_active', true),
            'stockBalances' => $balances,
        ];
    }

    /**
     * Render one page: prices resolved in a single query for the whole page,
     * stock read off the balances already loaded.
     *
     * @return list<array<string, mixed>>
     */
    private function grid(LengthAwarePaginator $products, ?int $customerId, ?int $warehouseId): array
    {
        $items = collect($products->items());

        $priced = $this->prices->resolveMany($items, $customerId);
        $variantStock = $this->variantStock($items, $warehouseId);

        return $items
            ->map(fn (Product $product) => (new PosProductResource(
                $product,
                $priced[$product->id] ?? [
                    'price' => (string) $product->selling_price,
                    'source' => PriceResolutionService::SOURCE_PRODUCT,
                    'price_list_id' => null,
                ],
                PosProductResource::stockOf($this->balancesFor($product, null)),
                $variantStock,
            ))->resolve())
            ->all();
    }

    /**
     * Per-variant availability for a whole page, keyed by variant id.
     *
     * Keyed globally rather than per product so the grid costs one query no
     * matter how many variable products are on the page. A variant with no
     * balance row is genuinely unstocked, which the resource renders as zero.
     */
    private function variantStock(Collection $products, ?int $warehouseId): Collection
    {
        $variantIds = $products->flatMap(fn (Product $product) => $product->variants->pluck('id'))->unique();

        if ($variantIds->isEmpty()) {
            return collect();
        }

        return StockBalance::query()
            ->whereIn('product_variant_id', $variantIds)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get(['product_variant_id', 'on_hand', 'reserved'])
            ->groupBy('product_variant_id')
            ->map(fn (Collection $rows) => PosProductResource::stockOf($rows));
    }

    /**
     * Balance rows that belong to the line being priced: the variant's only, or
     * the product's whole when no variant is in play.
     */
    private function balancesFor(Product $product, ?int $variantId): Collection
    {
        $balances = $product->relationLoaded('stockBalances') ? $product->stockBalances : collect();

        return $variantId === null
            ? $balances
            : $balances->where('product_variant_id', $variantId)->values();
    }

    /**
     * @return array{0: ?Product, 1: ?int}
     */
    private function resolveByCode(int $companyId, string $code): array
    {
        // Unlike the grid, this lookup does not filter on sellable or active:
        // a cashier who scans a staff-only item needs to be told it is barred,
        // not that the code is unknown — otherwise a disabled product looks
        // like a misread scanner and gets scanned again.
        $product = Product::query()
            ->where('company_id', $companyId)
            ->where(fn (Builder $q) => $q->where('sku', $code)->orWhere('barcode', $code))
            ->first();

        if ($product) {
            return [$product, null];
        }

        $barcode = ProductBarcode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($barcode) {
            return [$barcode->product, $barcode->product_variant_id];
        }

        $variant = ProductVariant::query()
            ->where('company_id', $companyId)
            ->where(fn (Builder $q) => $q->where('sku', $code)->orWhere('barcode', $code))
            ->first();

        return [$variant?->product, $variant?->id];
    }

    /**
     * Price the grid against the customer on the cashier's working cart, so the
     * tier shown is the tier checkout will charge.
     */
    private function cartCustomerId(Request $request): ?int
    {
        $companyId = $this->context->companyId();

        if (! $companyId) {
            return null;
        }

        return PosCart::query()
            ->working($companyId, $request->user()->id, $this->context->registerId())
            ->value('customer_id');
    }

    private function pageMeta(LengthAwarePaginator $products): array
    {
        return [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'from' => $products->firstItem(),
            'to' => $products->lastItem(),
        ];
    }
}
