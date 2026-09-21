<?php

namespace App\Http\Controllers\Api\V1\UnitConversions;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitConversion\StoreUnitConversionRequest;
use App\Http\Requests\UnitConversion\UpdateUnitConversionRequest;
use App\Http\Resources\UnitConversionResource;
use App\Models\UnitConversion;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitConversionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UnitConversion::class);

        $conversions = $this->scopedQuery($request)
            ->with(['fromUnit:id,code,name,type', 'toUnit:id,code,name,type'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(UnitConversionResource::collection($conversions));
    }

    public function store(StoreUnitConversionRequest $request): JsonResponse
    {
        $this->authorize('create', UnitConversion::class);
        $this->ensureCompanyAccess($request->company_id);

        $conversion = UnitConversion::create($request->validated());

        $this->audit->record('unit_conversion.create', 'unit_conversion', $conversion->id, null, $request->validated(), $conversion->company_id);

        return $this->success(new UnitConversionResource($conversion->load(['fromUnit', 'toUnit'])), 'Unit conversion created', 201);
    }

    public function show(UnitConversion $unitConversion): JsonResponse
    {
        $this->authorize('view', $unitConversion);

        return $this->success(new UnitConversionResource($unitConversion->load(['fromUnit', 'toUnit'])));
    }

    public function update(UpdateUnitConversionRequest $request, UnitConversion $unitConversion): JsonResponse
    {
        $this->authorize('update', $unitConversion);

        $old = $unitConversion->only(array_keys($request->validated()));

        $unitConversion->update($request->validated());

        $this->audit->record('unit_conversion.update', 'unit_conversion', $unitConversion->id, $old, $unitConversion->fresh()->only(array_keys($old)), $unitConversion->company_id);

        return $this->success(new UnitConversionResource($unitConversion->fresh()->load(['fromUnit', 'toUnit'])));
    }

    public function destroy(UnitConversion $unitConversion): JsonResponse
    {
        $this->authorize('delete', $unitConversion);

        $unitConversion->delete();

        $this->audit->record('unit_conversion.delete', 'unit_conversion', $unitConversion->id, $unitConversion->toArray(), null, $unitConversion->company_id);

        return $this->success(null, 'Unit conversion deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return UnitConversion::query()
            // Conversions carry their own company_id, so they are scoped the
            // same way every visibleTo entity is.
            ->when($user, fn ($q) => $q->whereIn('company_id', $user->companies()->select('companies.id')))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->from_unit_id, fn ($q, $unitId) => $q->where('from_unit_id', $unitId))
            ->when($request->to_unit_id, fn ($q, $unitId) => $q->where('to_unit_id', $unitId))
            ->when($request->sort && in_array($request->sort, ['factor', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
