<?php

namespace App\Http\Controllers\Api\V1\Taxes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\StoreTaxRequest;
use App\Http\Requests\Tax\UpdateTaxRequest;
use App\Http\Resources\TaxResource;
use App\Models\Tax;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tax::class);

        $taxes = $this->scopedQuery($request)
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(TaxResource::collection($taxes));
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        $this->authorize('create', Tax::class);
        $this->ensureCompanyAccess($request->company_id);

        $tax = Tax::create($request->validated());

        $this->audit->record('tax.create', 'tax', $tax->id, null, $request->validated(), $tax->company_id);

        return $this->success(new TaxResource($tax), 'Tax created', 201);
    }

    public function show(Tax $tax): JsonResponse
    {
        $this->authorize('view', $tax);

        return $this->success(new TaxResource($tax));
    }

    public function update(UpdateTaxRequest $request, Tax $tax): JsonResponse
    {
        $this->authorize('update', $tax);

        $old = $tax->only(array_keys($request->validated()));

        $tax->update($request->validated());

        $this->audit->record('tax.update', 'tax', $tax->id, $old, $tax->fresh()->only(array_keys($old)), $tax->company_id);

        return $this->success(new TaxResource($tax->fresh()));
    }

    public function destroy(Tax $tax): JsonResponse
    {
        $this->authorize('delete', $tax);

        $tax->delete();

        $this->audit->record('tax.delete', 'tax', $tax->id, $tax->toArray(), null, $tax->company_id);

        return $this->success(null, 'Tax deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Tax::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'rate', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
