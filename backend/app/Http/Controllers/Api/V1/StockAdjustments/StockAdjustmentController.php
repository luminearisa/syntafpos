<?php

namespace App\Http\Controllers\Api\V1\StockAdjustments;

use App\Enums\AdjustmentType;
use App\Enums\MovementType;
use App\Enums\StockAdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustment\StoreStockAdjustmentRequest;
use App\Http\Requests\StockAdjustment\UpdateStockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\Branch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\NumberingService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context,
        protected InventoryService $inventory,
        protected NumberingService $numbering
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $adjustments = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'items:id,stock_adjustment_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(StockAdjustmentResource::collection($adjustments));
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $this->authorize('create', StockAdjustment::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $adjustment = DB::transaction(function () use ($request) {
            $adjustment = StockAdjustment::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'location_id' => $request->location_id,
                'number' => $this->numbering->next('stock_adjustment', $request->company_id),
                'adjustment_date' => $request->adjustment_date,
                'adjustment_type' => $request->adjustment_type,
                'reason' => $request->reason,
                'status' => StockAdjustmentStatus::Draft,
                'requested_by' => $request->user()->id,
                'notes' => $request->notes,
            ]);

            $this->syncItems($adjustment, $request->items);

            return $adjustment;
        });

        $this->audit->record('adjustment.create', 'stock_adjustment', $adjustment->id, null, $adjustment->toArray(), $adjustment->company_id);

        return $this->success(new StockAdjustmentResource($adjustment->load('items')), 'Stock adjustment created', 201);
    }

    public function show(StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('view', $stockAdjustment);

        return $this->success(new StockAdjustmentResource($stockAdjustment->load(['warehouse', 'items'])));
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('update', $stockAdjustment);
        $this->guardNotPosted($stockAdjustment, 'updated');

        $old = $stockAdjustment->only(array_keys($request->validated()));

        $stockAdjustment = DB::transaction(function () use ($request, $stockAdjustment) {
            $stockAdjustment->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($stockAdjustment, $request->items);
            }

            return $stockAdjustment->fresh();
        });

        $this->audit->record('adjustment.update', 'stock_adjustment', $stockAdjustment->id, $old, $stockAdjustment->fresh()->only(array_keys($old)), $stockAdjustment->company_id);

        return $this->success(new StockAdjustmentResource($stockAdjustment->load('items')));
    }

    public function destroy(StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('delete', $stockAdjustment);
        $this->guardNotPosted($stockAdjustment, 'deleted');

        $stockAdjustment->delete();

        $this->audit->record('adjustment.delete', 'stock_adjustment', $stockAdjustment->id, $stockAdjustment->toArray(), null, $stockAdjustment->company_id);

        return $this->success(null, 'Stock adjustment deleted');
    }

    public function submit(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('submit', $stockAdjustment);
        $this->transition($stockAdjustment, StockAdjustmentStatus::Submitted, 'submit');

        return $this->success(new StockAdjustmentResource($stockAdjustment->fresh()->load('items')), 'Stock adjustment submitted');
    }

    public function approve(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('approve', $stockAdjustment);
        $this->transition($stockAdjustment, StockAdjustmentStatus::Approved, 'approve', ['approved_by' => $request->user()->id]);

        return $this->success(new StockAdjustmentResource($stockAdjustment->fresh()->load('items')), 'Stock adjustment approved');
    }

    public function post(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->authorize('post', $stockAdjustment);

        // The status flip and every movement share one transaction: if line
        // three of ten is rejected, lines one and two unwind and the document
        // stays 'approved'. A posted document with a half-applied ledger is the
        // failure mode this whole block exists to prevent.
        try {
            DB::transaction(function () use ($stockAdjustment, $request) {
                $this->transition($stockAdjustment, StockAdjustmentStatus::Posted, 'post', ['posted_at' => now()]);

                $type = $stockAdjustment->adjustment_type === AdjustmentType::Increase
                    ? MovementType::AdjustmentIn
                    : MovementType::AdjustmentOut;

                $where = [
                    'company_id' => $stockAdjustment->company_id,
                    'branch_id' => $stockAdjustment->branch_id,
                    'warehouse_id' => $stockAdjustment->warehouse_id,
                    'location_id' => $stockAdjustment->location_id,
                ];

                $stockAdjustment->items->each(function (StockAdjustmentItem $item) use ($stockAdjustment, $type, $where, $request) {
                    $this->inventory->move(
                        [
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'unit_id' => $item->unit_id,
                            'quantity' => (string) $item->quantity,
                        ],
                        $type,
                        $where,
                        $stockAdjustment,
                        (string) $item->unit_cost,
                        $request->user()->id,
                        "Adjustment {$stockAdjustment->number}"
                    );
                });
            });
        } catch (\RuntimeException $e) {
            // A rejected movement (insufficient stock, negative balance) is a
            // correctable client problem, so it surfaces as a 422 rather than a
            // 500; the transaction above has already fully rolled back.
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->success(new StockAdjustmentResource($stockAdjustment->fresh()->load('items')), 'Stock adjustment posted');
    }

    /**
     * Replace the adjustment's lines, snapshotting live stock for each.
     *
     * current_stock is read at write time for the same reason the opname freezes
     * system_quantity: the document must show what stock looked like when it was
     * raised, not what it looks like now.
     */
    private function syncItems(StockAdjustment $adjustment, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $adjustment->items()->delete();

        $adjustment->items()->createMany(
            collect($items)->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'unit_id' => $item['unit_id'],
                'quantity' => (string) $item['quantity'],
                'current_stock' => $this->inventory->onHand(
                    $item['product_id'],
                    $item['product_variant_id'] ?? null,
                    $adjustment->warehouse_id,
                    $adjustment->location_id
                ),
                'unit_cost' => (string) ($item['unit_cost'] ?? '0'),
                'notes' => $item['notes'] ?? null,
            ])->all()
        );
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(StockAdjustment $adjustment, StockAdjustmentStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            StockAdjustmentStatus::Submitted => StockAdjustmentStatus::Draft,
            StockAdjustmentStatus::Approved => StockAdjustmentStatus::Submitted,
            StockAdjustmentStatus::Posted => StockAdjustmentStatus::Approved,
            default => null,
        };

        if ($adjustment->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This stock adjustment is currently '{$adjustment->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $adjustment->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("adjustment.{$action}", 'stock_adjustment', $adjustment->id, null, ['status' => $to->value], $adjustment->company_id);
    }

    private function guardNotPosted(StockAdjustment $adjustment, string $action): void
    {
        if ($adjustment->status === StockAdjustmentStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => "A posted stock adjustment cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return StockAdjustment::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->adjustment_type, fn ($q, $type) => $q->where('adjustment_type', $type))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'adjustment_date', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

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
