<?php

namespace App\Http\Controllers\Api\V1\ProductPrices;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductPrice\StoreProductPriceRequest;
use App\Http\Requests\ProductPrice\UpdateProductPriceRequest;
use App\Http\Resources\ProductPriceResource;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPriceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductPrice::class);

        $prices = $this->scopedQuery($request)
            ->with(['priceList:id,company_id,name,currency', 'product:id,company_id,sku,name', 'productVariant:id,product_id,sku,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(ProductPriceResource::collection($prices));
    }

    public function store(StoreProductPriceRequest $request): JsonResponse
    {
        $this->authorize('create', ProductPrice::class);
        $this->ensureCompanyAccess($request->price_list_id, $request->product_id);

        $price = ProductPrice::create($request->validated());

        $this->audit->record(
            'price.change',
            'product_price',
            $price->id,
            null,
            $request->validated(),
            $price->priceList->company_id
        );

        return $this->success(new ProductPriceResource($price->load(['priceList', 'product', 'productVariant'])), 'Product price created', 201);
    }

    public function show(ProductPrice $productPrice): JsonResponse
    {
        $this->authorize('view', $productPrice);

        return $this->success(new ProductPriceResource($productPrice->load(['priceList', 'product', 'productVariant'])));
    }

    public function update(UpdateProductPriceRequest $request, ProductPrice $productPrice): JsonResponse
    {
        $this->authorize('update', $productPrice);

        $old = $productPrice->only(array_keys($request->validated()));

        $productPrice->update($request->validated());

        $this->audit->record(
            'price.change',
            'product_price',
            $productPrice->id,
            $old,
            $productPrice->fresh()->only(array_keys($old)),
            $productPrice->priceList->company_id
        );

        return $this->success(new ProductPriceResource($productPrice->fresh()->load(['priceList', 'product', 'productVariant'])));
    }

    public function destroy(ProductPrice $productPrice): JsonResponse
    {
        $this->authorize('delete', $productPrice);

        $productPrice->delete();

        $this->audit->record(
            'price.change',
            'product_price',
            $productPrice->id,
            $productPrice->toArray(),
            null,
            $productPrice->priceList->company_id
        );

        return $this->success(null, 'Product price deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();

        return ProductPrice::query()
            ->whereHas('priceList', fn (Builder $q) => $q->whereIn('company_id', $user->companies()->select('companies.id')))
            ->when($request->price_list_id, fn ($q, $id) => $q->where('price_list_id', $id))
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->product_variant_id, fn ($q, $id) => $q->where('product_variant_id', $id))
            ->when($request->price_type, fn ($q, $type) => $q->where('price_type', $type))
            ->when(
                $request->sort && in_array($request->sort, ['price', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    /**
     * The priced product must sit inside the price list's company.
     */
    private function ensureCompanyAccess(int $priceListId, int $productId): void
    {
        $priceList = PriceList::query()->where('id', $priceListId)->first();

        if (! $priceList) {
            throw new AuthorizationException('The selected price list does not exist.');
        }

        $product = Product::query()
            ->where('id', $productId)
            ->where('company_id', $priceList->company_id)
            ->exists();

        if (! $product) {
            throw new AuthorizationException('The selected product does not belong to this price list company.');
        }
    }
}
