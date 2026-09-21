<?php

namespace App\Http\Controllers\Api\V1\ProductBarcodes;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductBarcode\StoreProductBarcodeRequest;
use App\Http\Requests\ProductBarcode\UpdateProductBarcodeRequest;
use App\Http\Resources\ProductBarcodeResource;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductBarcodeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductBarcode::class);

        $barcodes = $this->scopedQuery($request)
            ->with(['product:id,company_id,sku,name', 'productVariant:id,product_id,sku,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(ProductBarcodeResource::collection($barcodes));
    }

    public function store(StoreProductBarcodeRequest $request): JsonResponse
    {
        $this->authorize('create', ProductBarcode::class);
        $this->ensureCompanyAccess($request->company_id, $request->product_id);

        $barcode = ProductBarcode::create($request->validated());

        $this->audit->record('barcode.create', 'product_barcode', $barcode->id, null, $request->validated(), $barcode->company_id);

        return $this->success(new ProductBarcodeResource($barcode->load(['product', 'productVariant'])), 'Barcode created', 201);
    }

    public function show(ProductBarcode $productBarcode): JsonResponse
    {
        $this->authorize('view', $productBarcode);

        return $this->success(new ProductBarcodeResource($productBarcode->load(['product', 'productVariant'])));
    }

    public function update(UpdateProductBarcodeRequest $request, ProductBarcode $productBarcode): JsonResponse
    {
        $this->authorize('update', $productBarcode);

        $old = $productBarcode->only(array_keys($request->validated()));

        $productBarcode->update($request->validated());

        $this->audit->record('barcode.update', 'product_barcode', $productBarcode->id, $old, $productBarcode->fresh()->only(array_keys($old)), $productBarcode->company_id);

        return $this->success(new ProductBarcodeResource($productBarcode->fresh()->load(['product', 'productVariant'])));
    }

    public function destroy(ProductBarcode $productBarcode): JsonResponse
    {
        $this->authorize('delete', $productBarcode);

        $productBarcode->delete();

        $this->audit->record('barcode.delete', 'product_barcode', $productBarcode->id, $productBarcode->toArray(), null, $productBarcode->company_id);

        return $this->success(null, 'Barcode deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return ProductBarcode::query()
            ->whereIn('company_id', $user->companies()->select('companies.id'))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->product_variant_id, fn ($q, $id) => $q->where('product_variant_id', $id))
            ->when($request->search, fn ($q, string $search) => $q->where('code', 'like', "%{$search}%"))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when(
                $request->sort && in_array($request->sort, ['code', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

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
