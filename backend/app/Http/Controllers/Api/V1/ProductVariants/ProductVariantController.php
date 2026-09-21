<?php

namespace App\Http\Controllers\Api\V1\ProductVariants;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariant\StoreProductVariantRequest;
use App\Http\Requests\ProductVariant\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVariantController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductVariant::class);

        $variants = $this->scopedQuery($request)
            ->with(['product:id,company_id,sku,name', 'attributeValues.attribute:id,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(ProductVariantResource::collection($variants));
    }

    public function store(StoreProductVariantRequest $request): JsonResponse
    {
        $this->authorize('create', ProductVariant::class);
        $this->ensureCompanyAccess($request->company_id, $request->product_id);

        $attributeValueIds = $request->array('attribute_value_ids');

        $variant = DB::transaction(function () use ($request, $attributeValueIds) {
            $variant = ProductVariant::create($request->safe()->except('attribute_value_ids'));

            if ($attributeValueIds) {
                $variant->attributeValues()->sync($attributeValueIds);
            }

            return $variant;
        });

        $this->audit->record('product_variant.create', 'product_variant', $variant->id, null, $request->validated(), $variant->company_id);

        return $this->success(new ProductVariantResource($variant->load(['product', 'attributeValues.attribute'])), 'Product variant created', 201);
    }

    public function show(ProductVariant $productVariant): JsonResponse
    {
        $this->authorize('view', $productVariant);

        return $this->success(new ProductVariantResource($productVariant->load(['product', 'attributeValues.attribute'])));
    }

    public function update(UpdateProductVariantRequest $request, ProductVariant $productVariant): JsonResponse
    {
        $this->authorize('update', $productVariant);

        $old = $productVariant->only(array_keys($request->validated()));

        $productVariant = DB::transaction(function () use ($request, $productVariant) {
            $productVariant->update($request->safe()->except('attribute_value_ids'));

            if ($request->has('attribute_value_ids')) {
                $productVariant->attributeValues()->sync($request->array('attribute_value_ids'));
            }

            return $productVariant;
        });

        $new = $productVariant->fresh()->only(array_keys($old));
        $new['attribute_value_ids'] = $productVariant->attributeValues()->pluck('attribute_values.id')->all();

        $this->audit->record('product_variant.update', 'product_variant', $productVariant->id, $old, $new, $productVariant->company_id);

        return $this->success(new ProductVariantResource($productVariant->load(['product', 'attributeValues.attribute'])));
    }

    public function destroy(ProductVariant $productVariant): JsonResponse
    {
        $this->authorize('delete', $productVariant);

        $productVariant->delete();

        $this->audit->record('product_variant.delete', 'product_variant', $productVariant->id, $productVariant->toArray(), null, $productVariant->company_id);

        return $this->success(null, 'Product variant deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return ProductVariant::query()
            ->whereIn('company_id', $user->companies()->select('companies.id'))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->search, function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when(
                $request->sort && in_array($request->sort, ['name', 'sku', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    /**
     * The variant must sit inside a product of the same company, and the acting
     * user must be able to reach that company.
     */
    private function ensureCompanyAccess(int $companyId, int $productId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        $product = Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $product) {
            throw new AuthorizationException('The selected product does not belong to this company.');
        }
    }
}
