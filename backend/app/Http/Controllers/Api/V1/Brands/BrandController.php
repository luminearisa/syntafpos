<?php

namespace App\Http\Controllers\Api\V1\Brands;

use App\Http\Controllers\Controller;
use App\Http\Requests\Brand\StoreBrandRequest;
use App\Http\Requests\Brand\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $brands = $this->scopedQuery($request)
            ->withCount('products')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(BrandResource::collection($brands));
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $this->authorize('create', Brand::class);
        $this->ensureCompanyAccess($request->company_id);

        $brand = Brand::create($request->validated());

        $this->audit->record('brand.create', 'brand', $brand->id, null, $request->validated(), $brand->company_id);

        return $this->success(new BrandResource($brand), 'Brand created', 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return $this->success(new BrandResource($brand));
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $old = $brand->only(array_keys($request->validated()));

        $brand->update($request->validated());

        $this->audit->record('brand.update', 'brand', $brand->id, $old, $brand->fresh()->only(array_keys($old)), $brand->company_id);

        return $this->success(new BrandResource($brand->fresh()));
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        $brand->delete();

        $this->audit->record('brand.delete', 'brand', $brand->id, $brand->toArray(), null, $brand->company_id);

        return $this->success(null, 'Brand deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Brand::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
