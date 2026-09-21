<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->scopedQuery($request)
            ->with(['customerGroup:id,company_id,name', 'priceList:id,company_id,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(CustomerResource::collection($customers));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);
        $this->ensureCompanyAccess($request->company_id);

        $customer = Customer::create($request->validated());

        $this->audit->record('customer.create', 'customer', $customer->id, null, $request->validated(), $customer->company_id);

        return $this->success(new CustomerResource($customer), 'Customer created', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return $this->success(new CustomerResource($customer->load(['customerGroup', 'priceList'])));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $old = $customer->only(array_keys($request->validated()));

        $customer->update($request->validated());

        $this->audit->record('customer.update', 'customer', $customer->id, $old, $customer->fresh()->only(array_keys($old)), $customer->company_id);

        return $this->success(new CustomerResource($customer->fresh()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        $this->audit->record('customer.delete', 'customer', $customer->id, $customer->toArray(), null, $customer->company_id);

        return $this->success(null, 'Customer deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Customer::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->customer_group_id, fn ($q, $id) => $q->where('customer_group_id', $id))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->search, function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->sort && in_array($request->sort, ['name', 'customer_code', 'created_at']),
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
