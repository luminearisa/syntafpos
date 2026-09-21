<?php

namespace App\Http\Controllers\Api\V1\Attributes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\StoreAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Resources\AttributeResource;
use App\Models\Attribute;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attribute::class);

        $attributes = $this->scopedQuery($request)
            ->withCount('values')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(AttributeResource::collection($attributes));
    }

    public function store(StoreAttributeRequest $request): JsonResponse
    {
        $this->authorize('create', Attribute::class);
        $this->ensureCompanyAccess($request->company_id);

        $attribute = Attribute::create($request->validated());

        $this->audit->record('attribute.create', 'attribute', $attribute->id, null, $request->validated(), $attribute->company_id);

        return $this->success(new AttributeResource($attribute), 'Attribute created', 201);
    }

    public function show(Attribute $attribute): JsonResponse
    {
        $this->authorize('view', $attribute);

        return $this->success(new AttributeResource($attribute->load('values')));
    }

    public function update(UpdateAttributeRequest $request, Attribute $attribute): JsonResponse
    {
        $this->authorize('update', $attribute);

        $old = $attribute->only(array_keys($request->validated()));

        $attribute->update($request->validated());

        $this->audit->record('attribute.update', 'attribute', $attribute->id, $old, $attribute->fresh()->only(array_keys($old)), $attribute->company_id);

        return $this->success(new AttributeResource($attribute->fresh()));
    }

    public function destroy(Attribute $attribute): JsonResponse
    {
        $this->authorize('delete', $attribute);

        // Values cascade with their attribute (on-delete cascade at the DB level).
        $attribute->delete();

        $this->audit->record('attribute.delete', 'attribute', $attribute->id, $attribute->toArray(), null, $attribute->company_id);

        return $this->success(null, 'Attribute deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Attribute::query()
            // Attributes own their company_id, so isolation is a company
            // membership filter, the same shape every visibleTo scope applies.
            ->when($user, fn ($q) => $q->whereIn('company_id', $user->companies()->select('companies.id')))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sort && in_array($request->sort, ['name', 'sort_order', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->orderBy('sort_order')->latest());
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
