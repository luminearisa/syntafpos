<?php

namespace App\Http\Controllers\Api\V1\PriceLists;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceList\StorePriceListRequest;
use App\Http\Requests\PriceList\UpdatePriceListRequest;
use App\Http\Resources\PriceListResource;
use App\Models\Branch;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PriceList::class);

        $priceLists = $this->scopedQuery($request)
            ->with(['customerGroup:id,company_id,name', 'branch:id,company_id,code,name'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(PriceListResource::collection($priceLists));
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
        $this->authorize('create', PriceList::class);
        $this->ensureCompanyAccess($request->company_id, $request->customer_group_id, $request->branch_id);

        $priceList = PriceList::create($request->validated());

        $this->audit->record('price.change', 'price_list', $priceList->id, null, $request->validated(), $priceList->company_id);

        return $this->success(new PriceListResource($priceList->load(['customerGroup', 'branch'])), 'Price list created', 201);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        $this->authorize('view', $priceList);

        return $this->success(new PriceListResource($priceList->load(['customerGroup', 'branch', 'prices'])));
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): JsonResponse
    {
        $this->authorize('update', $priceList);

        $old = $priceList->only(array_keys($request->validated()));

        $priceList->update($request->validated());

        $this->audit->record('price.change', 'price_list', $priceList->id, $old, $priceList->fresh()->only(array_keys($old)), $priceList->company_id);

        return $this->success(new PriceListResource($priceList->fresh()->load(['customerGroup', 'branch'])));
    }

    public function destroy(PriceList $priceList): JsonResponse
    {
        $this->authorize('delete', $priceList);

        $priceList->delete();

        $this->audit->record('price.change', 'price_list', $priceList->id, $priceList->toArray(), null, $priceList->company_id);

        return $this->success(null, 'Price list deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return PriceList::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, string $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('is_default'), fn ($q) => $q->where('is_default', true))
            ->when($request->customer_group_id, fn ($q, $id) => $q->where('customer_group_id', $id))
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(
                $request->sort && in_array($request->sort, ['name', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    /**
     * A price list may only point at a customer group and a branch of its own
     * company.
     */
    private function ensureCompanyAccess(int $companyId, ?int $customerGroupId, ?int $branchId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }

        if ($customerGroupId && ! CustomerGroup::query()->where('id', $customerGroupId)->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('The selected customer group does not belong to this company.');
        }

        if ($branchId && ! Branch::query()->where('id', $branchId)->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('The selected branch does not belong to this company.');
        }
    }
}
