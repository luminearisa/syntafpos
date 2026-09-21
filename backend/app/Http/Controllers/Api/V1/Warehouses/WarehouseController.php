<?php

namespace App\Http\Controllers\Api\V1\Warehouses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $warehouses = $this->scopedQuery($request)
            ->with(['branch:id,company_id,code,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(WarehouseResource::collection($warehouses));
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $this->authorize('create', Warehouse::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $warehouse = Warehouse::create($request->validated());

        $this->audit->record('warehouse.create', 'warehouse', $warehouse->id, null, $request->validated(), $warehouse->company_id);

        return $this->success(new WarehouseResource($warehouse), 'Warehouse created', 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $this->authorize('view', $warehouse);

        return $this->success(new WarehouseResource($warehouse->load('branch')));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorize('update', $warehouse);

        $old = $warehouse->only(array_keys($request->validated()));

        $warehouse->update($request->validated());

        $this->audit->record('warehouse.update', 'warehouse', $warehouse->id, $old, $warehouse->fresh()->only(array_keys($old)), $warehouse->company_id);

        return $this->success(new WarehouseResource($warehouse->fresh()));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $this->authorize('delete', $warehouse);

        $warehouse->delete();

        $this->audit->record('warehouse.delete', 'warehouse', $warehouse->id, $warehouse->toArray(), null, $warehouse->company_id);

        return $this->success(null, 'Warehouse deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Warehouse::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->branch_id, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    /**
     * Cross-company integrity: a warehouse may only belong to a branch of the
     * same company, and the acting user must be able to reach that company.
     */
    private function ensureCompanyAccess(int $companyId, ?int $branchId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        if ($branchId) {
            $branch = Branch::query()
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();

            if (! $branch) {
                throw new AuthorizationException('The selected branch does not belong to this company.');
            }
        }
    }
}
