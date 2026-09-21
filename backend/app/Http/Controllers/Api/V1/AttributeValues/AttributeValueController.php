<?php

namespace App\Http\Controllers\Api\V1\AttributeValues;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttributeValue\StoreAttributeValueRequest;
use App\Http\Requests\AttributeValue\UpdateAttributeValueRequest;
use App\Http\Resources\AttributeValueResource;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeValueController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttributeValue::class);

        $values = $this->scopedQuery($request)
            ->with('attribute:id,company_id,name')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(AttributeValueResource::collection($values));
    }

    public function store(StoreAttributeValueRequest $request): JsonResponse
    {
        $this->authorize('create', AttributeValue::class);

        $attribute = Attribute::query()
            ->whereIn('company_id', $request->user()->companies()->select('companies.id'))
            ->whereKey($request->attribute_id)
            ->firstOrFail();

        $this->ensureCompanyAccess($attribute->company_id);

        $value = AttributeValue::create($request->validated());

        $this->audit->record('attribute_value.create', 'attribute_value', $value->id, null, $request->validated(), $attribute->company_id);

        return $this->success(new AttributeValueResource($value->load('attribute')), 'Attribute value created', 201);
    }

    public function show(AttributeValue $attributeValue): JsonResponse
    {
        $this->authorize('view', $attributeValue);

        return $this->success(new AttributeValueResource($attributeValue->load('attribute')));
    }

    public function update(UpdateAttributeValueRequest $request, AttributeValue $attributeValue): JsonResponse
    {
        $this->authorize('update', $attributeValue);

        $old = $attributeValue->only(array_keys($request->validated()));

        $attributeValue->update($request->validated());

        $this->audit->record(
            'attribute_value.update',
            'attribute_value',
            $attributeValue->id,
            $old,
            $attributeValue->fresh()->only(array_keys($old)),
            $attributeValue->attribute?->company_id
        );

        return $this->success(new AttributeValueResource($attributeValue->fresh()->load('attribute')));
    }

    public function destroy(AttributeValue $attributeValue): JsonResponse
    {
        $this->authorize('delete', $attributeValue);

        $attributeValue->delete();

        $this->audit->record(
            'attribute_value.delete',
            'attribute_value',
            $attributeValue->id,
            $attributeValue->toArray(),
            null,
            $attributeValue->attribute?->company_id
        );

        return $this->success(null, 'Attribute value deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        // Values have no company column of their own, so isolation rides the
        // attribute they belong to.
        $attributeIds = Attribute::query()
            ->when($user, fn ($q) => $q->whereIn('company_id', $user->companies()->select('companies.id')))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->select('id');

        return AttributeValue::query()
            ->whereIn('attribute_id', $attributeIds)
            ->when($request->attribute_id, fn ($q, $attributeId) => $q->where('attribute_id', $attributeId))
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
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
