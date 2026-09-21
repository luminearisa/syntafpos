<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductLookupResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->scopedQuery($request)
            ->with($this->eagerLoad())
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(ProductResource::collection($products));
    }

    /**
     * Point-of-sale lookup: resolve a code to a sellable product line.
     *
     * Declared before the resource so it never collides with the {product}
     * show route, and served without pagination because a till scans one code
     * at a time and needs a single answer in one round trip.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $request->validate(['barcode' => ['required', 'string', 'max:128']]);

        $code = $request->string('barcode')->toString();
        $companyId = $this->context->companyId();

        if (! $companyId) {
            throw new AuthorizationException('No active company context.');
        }

        [$product, $variantId] = $this->resolveByCode($code, $companyId);

        if (! $product) {
            return $this->error('No product matches the given code.', 404);
        }

        $product->load($this->eagerLoad());

        return $this->success(new ProductLookupResource($product, $variantId));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);
        $this->ensureCompanyAccess($request->company_id);

        $product = Product::create($request->validated());

        $this->audit->record('product.create', 'product', $product->id, null, $request->validated(), $product->company_id);

        return $this->success(new ProductResource($product->load($this->eagerLoad())), 'Product created', 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return $this->success(new ProductResource($product->load($this->eagerLoad())));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $old = $product->only(array_keys($request->validated()));

        $product->update($request->validated());

        $this->audit->record('product.update', 'product', $product->id, $old, $product->fresh()->only(array_keys($old)), $product->company_id);

        return $this->success(new ProductResource($product->fresh()->load($this->eagerLoad())));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        $this->audit->record('product.delete', 'product', $product->id, $product->toArray(), null, $product->company_id);

        return $this->success(null, 'Product deleted');
    }

    /**
     * Resolve a scanned or typed code to its product and variant.
     *
     * Every branch of the search rides a company-scoped unique or covering
     * index (products.sku, products.barcode, product_barcodes.code,
     * product_variants.sku), so the lookup stays an index seek.
     *
     * @return array{0: ?Product, 1: ?int}
     */
    private function resolveByCode(string $code, int $companyId): array
    {
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

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Product::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->brand_id, fn ($q, $id) => $q->where('brand_id', $id))
            ->when($request->product_type, fn ($q, $type) => $q->where('product_type', $type))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->stock_status, fn ($q, $status) => $this->applyStockStatus($q, $status))
            ->when(
                $request->sort && in_array($request->sort, ['name', 'sku', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    /**
     * Filter by a derived stock status.
     *
     * A correlated sum over stock_balances keeps the count correct on every
     * driver without a join, so no column becomes ambiguous and the reorder
     * comparison stays per product.
     */
    private function applyStockStatus(Builder $q, string $status): void
    {
        $onHand = 'coalesce((select sum(stock_balances.on_hand) from stock_balances where stock_balances.product_id = products.id), 0)';

        match ($status) {
            'out_of_stock' => $q->whereRaw("{$onHand} <= 0"),
            'low_stock' => $q->whereRaw("{$onHand} > 0")->whereRaw("{$onHand} <= products.reorder_point"),
            'in_stock' => $q->whereRaw("{$onHand} > products.reorder_point"),
            default => null,
        };
    }

    /**
     * Relations the resource needs to compute stock and costing.
     */
    private function eagerLoad(): array
    {
        return [
            'category:id,company_id,name',
            'brand:id,company_id,name',
            'defaultUnit:id,company_id,name,code',
            'stockBalances:id,product_id,on_hand,reserved,average_cost,last_cost,last_movement_at',
        ];
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
