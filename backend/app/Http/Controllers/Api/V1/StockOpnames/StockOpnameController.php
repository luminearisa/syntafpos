<?php

namespace App\Http\Controllers\Api\V1\StockOpnames;

use App\Enums\MovementType;
use App\Enums\StockOpnameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockOpname\StoreStockOpnameRequest;
use App\Http\Requests\StockOpname\UpdateStockOpnameRequest;
use App\Http\Resources\StockOpnameResource;
use App\Models\Branch;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
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

class StockOpnameController extends Controller
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
        $this->authorize('viewAny', StockOpname::class);

        $opnames = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'items:id,stock_opname_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(StockOpnameResource::collection($opnames));
    }

    public function store(StoreStockOpnameRequest $request): JsonResponse
    {
        $this->authorize('create', StockOpname::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $opname = DB::transaction(function () use ($request) {
            $opname = StockOpname::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'location_id' => $request->location_id,
                'number' => $this->numbering->next('stock_opname', $request->company_id),
                'opname_date' => $request->opname_date,
                'status' => StockOpnameStatus::Draft,
                'notes' => $request->notes,
            ]);

            $this->syncItems($opname, $request->items);

            return $opname;
        });

        $this->audit->record('opname.create', 'stock_opname', $opname->id, null, $opname->toArray(), $opname->company_id);

        return $this->success(new StockOpnameResource($opname->load('items')), 'Stock opname created', 201);
    }

    public function show(StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('view', $stockOpname);

        return $this->success(new StockOpnameResource($stockOpname->load(['warehouse', 'items'])));
    }

    public function update(UpdateStockOpnameRequest $request, StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('update', $stockOpname);
        $this->guardNotPosted($stockOpname, 'updated');

        $old = $stockOpname->only(array_keys($request->validated()));

        $stockOpname = DB::transaction(function () use ($request, $stockOpname) {
            $stockOpname->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($stockOpname, $request->items);
            }

            return $stockOpname->fresh();
        });

        $this->audit->record('opname.update', 'stock_opname', $stockOpname->id, $old, $stockOpname->fresh()->only(array_keys($old)), $stockOpname->company_id);

        return $this->success(new StockOpnameResource($stockOpname->load('items')));
    }

    public function destroy(StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('delete', $stockOpname);
        $this->guardNotPosted($stockOpname, 'deleted');

        $stockOpname->delete();

        $this->audit->record('opname.delete', 'stock_opname', $stockOpname->id, $stockOpname->toArray(), null, $stockOpname->company_id);

        return $this->success(null, 'Stock opname deleted');
    }

    public function startCounting(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('startCounting', $stockOpname);
        $this->transition($stockOpname, StockOpnameStatus::Counting, 'count', [
            'counted_by' => $request->user()->id,
            'counted_at' => now(),
        ]);

        // The system side of the sheet is frozen the moment counting opens: the
        // comparison must be against stock as it stood at count time, never the
        // live balance recomputed later, or the sheet would silently capture
        // every movement made while someone walked the aisle.
        $this->snapshotSystemQuantities($stockOpname);

        return $this->success(new StockOpnameResource($stockOpname->fresh()->load('items')), 'Stock opname counting started');
    }

    public function sendToReview(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('sendToReview', $stockOpname);
        $this->guardCounted($stockOpname);
        $this->recomputeDifferences($stockOpname);
        $this->transition($stockOpname, StockOpnameStatus::Review, 'review', [
            'reviewed_by' => $request->user()->id,
        ]);

        return $this->success(new StockOpnameResource($stockOpname->fresh()->load('items')), 'Stock opname sent for review');
    }

    public function approve(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('approve', $stockOpname);
        $this->transition($stockOpname, StockOpnameStatus::Approved, 'approve', [
            'approved_by' => $request->user()->id,
        ]);

        return $this->success(new StockOpnameResource($stockOpname->fresh()->load('items')), 'Stock opname approved');
    }

    public function post(Request $request, StockOpname $stockOpname): JsonResponse
    {
        $this->authorize('post', $stockOpname);

        // The status flip and every adjustment movement share one transaction,
        // so a rejected movement leaves the sheet 'approved' with no ledger
        // rows written: stock never lands in a half-reconciled state.
        try {
            DB::transaction(function () use ($stockOpname, $request) {
                $this->transition($stockOpname, StockOpnameStatus::Posted, 'post', ['posted_at' => now()]);

                $where = [
                    'company_id' => $stockOpname->company_id,
                    'branch_id' => $stockOpname->branch_id,
                    'warehouse_id' => $stockOpname->warehouse_id,
                    'location_id' => $stockOpname->location_id,
                ];

                $stockOpname->items->each(function (StockOpnameItem $item) use ($stockOpname, $where, $request) {
                    // A sheet that matches the book needs no movement at all.
                    $difference = (string) $item->difference;

                    if (bccomp($difference, '0', 6) === 0) {
                        return;
                    }

                    $this->inventory->move(
                        [
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'unit_id' => $item->unit_id,
                            // Absolute value: the movement type carries the sign.
                            'quantity' => ltrim($difference, '-'),
                        ],
                        bccomp($difference, '0', 6) > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                        $where,
                        $stockOpname,
                        (string) $item->unit_cost,
                        $request->user()->id,
                        "Stock opname {$stockOpname->number}"
                    );
                });
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->success(new StockOpnameResource($stockOpname->fresh()->load('items')), 'Stock opname posted');
    }

    /**
     * Write the count sheet, snapshotting the book balance for every new line.
     *
     * Lines that already exist keep the system_quantity they were frozen at:
     * an edit made while the sheet is open must not quietly re-read live stock,
     * or the count would capture drift that happened during the count itself.
     */
    private function syncItems(StockOpname $opname, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $frozen = $opname->items->keyBy(
            fn (StockOpnameItem $item) => "{$item->product_id}:{$item->product_variant_id}:{$item->unit_id}"
        );

        $opname->items()->delete();

        $opname->items()->createMany(
            collect($items)->map(function (array $item) use ($opname, $frozen) {
                $key = "{$item['product_id']}:".($item['product_variant_id'] ?? null).":{$item['unit_id']}";
                $system = ($frozen[$key] ?? null)?->system_quantity
                    ?? $this->inventory->onHand(
                        $item['product_id'],
                        $item['product_variant_id'] ?? null,
                        $opname->warehouse_id,
                        $opname->location_id
                    );

                return [
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'],
                    'system_quantity' => $system,
                    'counted_quantity' => isset($item['counted_quantity']) ? (string) $item['counted_quantity'] : null,
                    'difference' => isset($item['counted_quantity'])
                        ? bcsub((string) $item['counted_quantity'], (string) $system, 6)
                        : '0',
                    'unit_cost' => (string) ($item['unit_cost'] ?? '0'),
                    'notes' => $item['notes'] ?? null,
                ];
            })->all()
        );
    }

    /**
     * Refresh the frozen book balance of every line against the live ledger.
     */
    private function snapshotSystemQuantities(StockOpname $opname): void
    {
        $opname->items->each(function (StockOpnameItem $item) use ($opname) {
            $system = $this->inventory->onHand(
                $item->product_id,
                $item->product_variant_id,
                $opname->warehouse_id,
                $opname->location_id
            );

            $item->forceFill([
                'system_quantity' => $system,
                'difference' => $item->counted_quantity === null
                    ? '0'
                    : bcsub((string) $item->counted_quantity, $system, 6),
            ])->save();
        });
    }

    /**
     * Recompute every difference from the frozen system quantity.
     */
    private function recomputeDifferences(StockOpname $opname): void
    {
        $opname->items->each(function (StockOpnameItem $item) {
            $item->forceFill([
                'difference' => bcsub((string) $item->counted_quantity, (string) $item->system_quantity, 6),
            ])->save();
        });
    }

    /**
     * A sheet cannot leave counting while any line is still uncounted.
     */
    private function guardCounted(StockOpname $opname): void
    {
        $uncounted = $opname->items()->whereNull('counted_quantity')->count();

        if ($uncounted > 0) {
            throw ValidationException::withMessages([
                'items' => "Cannot send the stock opname to review: {$uncounted} item(s) have not been counted.",
            ]);
        }
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(StockOpname $opname, StockOpnameStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            StockOpnameStatus::Counting => StockOpnameStatus::Draft,
            StockOpnameStatus::Review => StockOpnameStatus::Counting,
            StockOpnameStatus::Approved => StockOpnameStatus::Review,
            StockOpnameStatus::Posted => StockOpnameStatus::Approved,
            default => null,
        };

        if ($opname->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This stock opname is currently '{$opname->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $opname->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("opname.{$action}", 'stock_opname', $opname->id, null, ['status' => $to->value], $opname->company_id);
    }

    private function guardNotPosted(StockOpname $opname, string $action): void
    {
        if ($opname->status === StockOpnameStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => "A posted stock opname cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return StockOpname::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'opname_date', 'created_at']),
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
