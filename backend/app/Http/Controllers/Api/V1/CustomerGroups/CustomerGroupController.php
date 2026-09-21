<?php

namespace App\Http\Controllers\Api\V1\CustomerGroups;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerGroup\StoreCustomerGroupRequest;
use App\Http\Requests\CustomerGroup\UpdateCustomerGroupRequest;
use App\Http\Resources\CustomerGroupResource;
use App\Models\CustomerGroup;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerGroupController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerGroup::class);

        $groups = $this->scopedQuery($request)
            ->withCount('customers')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(CustomerGroupResource::collection($groups));
    }

    public function store(StoreCustomerGroupRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerGroup::class);
        $this->ensureCompanyAccess($request->company_id);

        $group = CustomerGroup::create($request->validated());

        $this->audit->record('customer_group.create', 'customer_group', $group->id, null, $request->validated(), $group->company_id);

        return $this->success(new CustomerGroupResource($group), 'Customer group created', 201);
    }

    public function show(CustomerGroup $customerGroup): JsonResponse
    {
        $this->authorize('view', $customerGroup);

        return $this->success(new CustomerGroupResource($customerGroup->loadCount('customers')));
    }

    public function update(UpdateCustomerGroupRequest $request, CustomerGroup $customerGroup): JsonResponse
    {
        $this->authorize('update', $customerGroup);

        $old = $customerGroup->only(array_keys($request->validated()));

        $customerGroup->update($request->validated());

        $this->audit->record('customer_group.update', 'customer_group', $customerGroup->id, $old, $customerGroup->fresh()->only(array_keys($old)), $customerGroup->company_id);

        return $this->success(new CustomerGroupResource($customerGroup->fresh()));
    }

    public function destroy(CustomerGroup $customerGroup): JsonResponse
    {
        $this->authorize('delete', $customerGroup);

        $customerGroup->delete();

        $this->audit->record('customer_group.delete', 'customer_group', $customerGroup->id, $customerGroup->toArray(), null, $customerGroup->company_id);

        return $this->success(null, 'Customer group deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return CustomerGroup::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when(
                $request->sort && in_array($request->sort, ['name', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
