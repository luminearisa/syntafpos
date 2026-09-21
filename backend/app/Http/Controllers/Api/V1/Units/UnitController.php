<?php

namespace App\Http\Controllers\Api\V1\Units;

use App\Http\Controllers\Controller;
use App\Http\Requests\Unit\ConvertUnitRequest;
use App\Http\Requests\Unit\StoreUnitRequest;
use App\Http\Requests\Unit\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    use AuthorizesRequests;

    /**
     * Working scale for conversion math.
     *
     * The factor column is decimal(20,10), so 10 fractional digits is the
     * widest exact result the stored factor can express.
     */
    private const CONVERSION_SCALE = 10;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Unit::class);

        $units = $this->scopedQuery($request)
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(UnitResource::collection($units));
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $this->authorize('create', Unit::class);
        $this->ensureCompanyAccess($request->company_id);

        $unit = DB::transaction(function () use ($request) {
            $unit = Unit::create($request->validated());

            // Only one base unit may exist per company and unit type.
            if ($unit->is_base) {
                $this->claimBase($unit);
            }

            return $unit;
        });

        $this->audit->record('unit.create', 'unit', $unit->id, null, $request->validated(), $unit->company_id);

        return $this->success(new UnitResource($unit), 'Unit created', 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        return $this->success(new UnitResource($unit));
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $this->authorize('update', $unit);

        $old = $unit->only(array_keys($request->validated()));

        DB::transaction(function () use ($request, $unit): void {
            $unit->update($request->validated());

            if ($unit->fresh()->is_base) {
                $this->claimBase($unit);
            }
        });

        $this->audit->record('unit.update', 'unit', $unit->id, $old, $unit->fresh()->only(array_keys($old)), $unit->company_id);

        return $this->success(new UnitResource($unit->fresh()));
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        $unit->delete();

        $this->audit->record('unit.delete', 'unit', $unit->id, $unit->toArray(), null, $unit->company_id);

        return $this->success(null, 'Unit deleted');
    }

    /**
     * Convert a quantity from this unit into the requested target unit.
     *
     * Conversions are directed rows: carton -> pieces uses the carton row, and
     * the reciprocal pieces -> carton is a separate row so the factor stays
     * explicit instead of being derived by division. Both directions must be
     * defined to be usable; a missing row answers 404.
     */
    public function convert(ConvertUnitRequest $request, Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        $target = Unit::query()
            ->visibleTo($request->user())
            ->whereKey($request->integer('to'))
            ->first();

        if ($target === null) {
            return $this->error('The target unit does not exist in your companies.', 404);
        }

        $quantity = (string) $request->quantity;

        if ($unit->is($target)) {
            $factor = '1';
            $result = $quantity;
        } else {
            $conversion = $unit->conversionTo($target);

            if ($conversion === null) {
                return $this->error(
                    "No conversion is defined from {$unit->code} to {$target->code}.",
                    404
                );
            }

            $factor = (string) $conversion->factor;
            $result = bcmul($quantity, $factor, self::CONVERSION_SCALE);
        }

        return $this->success([
            'from_unit_id' => $unit->id,
            'to_unit_id' => $target->id,
            'from_unit_code' => $unit->code,
            'to_unit_code' => $target->code,
            'quantity' => $quantity,
            'factor' => $factor,
            'result' => $result,
        ]);
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Unit::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->boolean('is_base'), fn ($q) => $q->where('is_base', true))
            ->when($request->sort && in_array($request->sort, ['name', 'code', 'created_at']), fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'), fn ($q) => $q->latest());
    }

    /**
     * Make the given unit the only base unit of its type in its company.
     */
    private function claimBase(Unit $unit): void
    {
        Unit::query()
            ->where('company_id', $unit->company_id)
            ->where('type', $unit->type?->value ?? 'quantity')
            ->where('is_base', true)
            ->whereKeyNot($unit->id)
            ->update(['is_base' => false]);
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
