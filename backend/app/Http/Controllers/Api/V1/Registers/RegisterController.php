<?php

namespace App\Http\Controllers\Api\V1\Registers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Register\StoreRegisterRequest;
use App\Http\Requests\Register\UpdateRegisterRequest;
use App\Http\Resources\RegisterResource;
use App\Models\Branch;
use App\Models\Register;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Register::class);

        $registers = $this->scopedQuery($request)
            ->with(['branch:id,company_id,code,name', 'warehouse:id,code,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(RegisterResource::collection($registers));
    }

    public function store(StoreRegisterRequest $request): JsonResponse
    {
        $this->authorize('create', Register::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id, $request->warehouse_id);

        $register = Register::create($request->validated());

        $this->audit->record('register.create', 'register', $register->id, null, $request->validated(), $register->company_id);

        return $this->success(new RegisterResource($register), 'Register created', 201);
    }

    public function show(Register $register): JsonResponse
    {
        $this->authorize('view', $register);

        return $this->success(new RegisterResource($register->load(['branch', 'warehouse'])));
    }

    public function update(UpdateRegisterRequest $request, Register $register): JsonResponse
    {
        $this->authorize('update', $register);

        $old = $register->only(array_keys($request->validated()));

        $register->update($request->validated());

        $this->audit->record('register.update', 'register', $register->id, $old, $register->fresh()->only(array_keys($old)), $register->company_id);

        return $this->success(new RegisterResource($register->fresh()));
    }

    public function destroy(Register $register): JsonResponse
    {
        $this->authorize('delete', $register);

        $register->delete();

        $this->audit->record('register.delete', 'register', $register->id, $register->toArray(), null, $register->company_id);

        return $this->success(null, 'Register deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Register::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->branch_id, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    /**
     * Cross-company integrity: branch and warehouse must belong to the same
     * company as the register itself, and the user must reach that company.
     */
    private function ensureCompanyAccess(int $companyId, ?int $branchId, ?int $warehouseId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        if ($branchId && ! Branch::query()->where('id', $branchId)->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('The selected branch does not belong to this company.');
        }

        if ($warehouseId && ! Warehouse::query()->where('id', $warehouseId)->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('The selected warehouse does not belong to this company.');
        }
    }
}
