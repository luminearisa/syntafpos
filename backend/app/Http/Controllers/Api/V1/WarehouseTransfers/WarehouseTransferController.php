<?php

namespace App\Http\Controllers\Api\V1\WarehouseTransfers;

use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseTransfer\ReceiveWarehouseTransferRequest;
use App\Http\Requests\WarehouseTransfer\StoreWarehouseTransferRequest;
use App\Http\Requests\WarehouseTransfer\UpdateWarehouseTransferRequest;
use App\Http\Resources\WarehouseTransferResource;
use App\Models\StockBalance;
use App\Models\WarehouseTransfer;
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

class WarehouseTransferController extends Controller
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
        $this->authorize('viewAny', WarehouseTransfer::class);

        $transfers = $this->scopedQuery($request)
            ->with([
                'fromWarehouse:id,company_id,code,name',
                'toWarehouse:id,company_id,code,name',
                'items:id,warehouse_transfer_id',
            ])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(WarehouseTransferResource::collection($transfers));
    }

    public function store(StoreWarehouseTransferRequest $request): JsonResponse
    {
        $this->authorize('create', WarehouseTransfer::class);
        $this->ensureCompanyAccess($request->company_id);

        $transfer = DB::transaction(function () use ($request) {
            $transfer = WarehouseTransfer::create([
                'company_id' => $request->company_id,
                'number' => $this->numbering->next('warehouse_transfer', $request->company_id),
                'transfer_date' => $request->transfer_date,
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
                'from_location_id' => $request->from_location_id,
                'to_location_id' => $request->to_location_id,
                'status' => TransferStatus::Draft,
                'requested_by' => $request->user()->id,
                'notes' => $request->notes,
            ]);

            $this->syncItems($transfer, $request->items);

            return $transfer;
        });

        $this->audit->record('transfer.create', 'warehouse_transfer', $transfer->id, null, $transfer->toArray(), $transfer->company_id);

        return $this->success(new WarehouseTransferResource($transfer->load('items')), 'Warehouse transfer created', 201);
    }

    public function show(WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('view', $warehouseTransfer);

        return $this->success(new WarehouseTransferResource($warehouseTransfer->load(['fromWarehouse', 'toWarehouse', 'items'])));
    }

    public function update(UpdateWarehouseTransferRequest $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('update', $warehouseTransfer);
        $this->guardInMotion($warehouseTransfer, 'updated');

        $old = $warehouseTransfer->only(array_keys($request->validated()));

        $warehouseTransfer = DB::transaction(function () use ($request, $warehouseTransfer) {
            $warehouseTransfer->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($warehouseTransfer, $request->items);
            }

            return $warehouseTransfer->fresh();
        });

        $this->audit->record('transfer.update', 'warehouse_transfer', $warehouseTransfer->id, $old, $warehouseTransfer->fresh()->only(array_keys($old)), $warehouseTransfer->company_id);

        return $this->success(new WarehouseTransferResource($warehouseTransfer->load('items')));
    }

    public function destroy(WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('delete', $warehouseTransfer);

        // Only a draft can be discarded: anything further along has a document
        // number that may already be referenced, and a shipped transfer has
        // real ledger rows tied to it.
        if ($warehouseTransfer->status !== TransferStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "Only a draft warehouse transfer can be deleted; this one is '{$warehouseTransfer->status->value}'.",
            ]);
        }

        $warehouseTransfer->delete();

        $this->audit->record('transfer.delete', 'warehouse_transfer', $warehouseTransfer->id, $warehouseTransfer->toArray(), null, $warehouseTransfer->company_id);

        return $this->success(null, 'Warehouse transfer deleted');
    }

    public function submit(Request $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('submit', $warehouseTransfer);
        $this->transition($warehouseTransfer, TransferStatus::Submitted, 'submit');

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), 'Warehouse transfer submitted');
    }

    public function approve(Request $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('approve', $warehouseTransfer);
        $this->transition($warehouseTransfer, TransferStatus::Approved, 'approve', ['approved_by' => $request->user()->id]);

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), 'Warehouse transfer approved');
    }

    public function ship(Request $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('ship', $warehouseTransfer);

        $warehouseTransfer->load(['items.product', 'fromWarehouse']);

        // Availability is checked before the status flips, so a line that
        // cannot ship leaves the transfer 'approved' and untouched rather than
        // marked as gone with nothing behind it.
        $this->guardAvailableStock($warehouseTransfer);

        $this->transition($warehouseTransfer, TransferStatus::Shipped, 'ship', ['shipped_at' => now()]);

        try {
            DB::transaction(function () use ($warehouseTransfer, $request) {
                $where = $this->sourceWhere($warehouseTransfer);

                $warehouseTransfer->items->each(fn ($item) => $this->inventory->move(
                    [
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'unit_id' => $item->unit_id,
                        'quantity' => (string) $item->quantity,
                    ],
                    MovementType::TransferOut,
                    $where,
                    $warehouseTransfer,
                    $this->transferCost($warehouseTransfer, $item),
                    $request->user()->id,
                    "Transfer out {$warehouseTransfer->number}"
                ));
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), 'Warehouse transfer shipped');
    }

    public function receive(ReceiveWarehouseTransferRequest $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('receive', $warehouseTransfer);
        $this->guardReceivable($warehouseTransfer);

        $warehouseTransfer->load(['items.product', 'toWarehouse']);

        // Resolve and validate every line against what is still outstanding
        // before any of it lands, so a bad line never partly receives the rest.
        $receipts = collect($request->items)
            ->map(function (array $row) use ($warehouseTransfer) {
                $item = $warehouseTransfer->items->firstWhere('id', $row['id']);

                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => "Item {$row['id']} does not belong to this warehouse transfer.",
                    ]);
                }

                $quantity = (string) $row['quantity_received'];
                $outstanding = $item->quantityOutstanding();

                if (bccomp($quantity, $outstanding, 6) > 0) {
                    throw ValidationException::withMessages([
                        'items' => "Item {$item->product->sku}: cannot receive {$quantity}, only {$outstanding} outstanding.",
                    ]);
                }

                return ['item' => $item, 'quantity' => $quantity];
            });

        try {
            // The closure returns whether everything landed, because a PHP
            // closure cannot write back to an outer scalar.
            $fullyReceived = DB::transaction(function () use ($warehouseTransfer, $request, $receipts) {
                $where = $this->destinationWhere($warehouseTransfer);

                $receipts->each(function (array $receipt) use ($warehouseTransfer, $where, $request) {
                    $item = $receipt['item'];
                    $quantity = $receipt['quantity'];

                    // No movement for an empty line: the destination only moves
                    // when goods actually arrive.
                    if (bccomp($quantity, '0', 6) === 0) {
                        return;
                    }

                    $this->inventory->move(
                        [
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'unit_id' => $item->unit_id,
                            'quantity' => $quantity,
                        ],
                        MovementType::TransferIn,
                        $where,
                        $warehouseTransfer,
                        $this->transferCost($warehouseTransfer, $item),
                        $request->user()->id,
                        "Transfer in {$warehouseTransfer->number}"
                    );

                    $item->forceFill([
                        'quantity_received' => bcadd((string) $item->quantity_received, $quantity, 6),
                    ])->save();
                });

                // The transfer closes only when nothing is left outstanding;
                // a partial receipt leaves it open for the next delivery.
                $complete = $warehouseTransfer->fresh()->items
                    ->every(fn ($item) => bccomp($item->quantityOutstanding(), '0', 6) === 0);

                if ($complete) {
                    $warehouseTransfer->forceFill([
                        'status' => TransferStatus::Received,
                        'received_at' => now(),
                    ])->save();

                    $this->audit->record('transfer.receive', 'warehouse_transfer', $warehouseTransfer->id, null, ['status' => 'received'], $warehouseTransfer->company_id);
                } else {
                    $this->audit->record('transfer.receive_partial', 'warehouse_transfer', $warehouseTransfer->id, null, null, $warehouseTransfer->company_id);
                }

                return $complete;
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $message = $fullyReceived ? 'Warehouse transfer fully received' : 'Warehouse transfer partially received';

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), $message);
    }

    public function complete(Request $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('complete', $warehouseTransfer);
        $this->transition($warehouseTransfer, TransferStatus::Completed, 'complete');

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), 'Warehouse transfer completed');
    }

    public function cancel(Request $request, WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorize('cancel', $warehouseTransfer);

        // Goods in motion cannot be cancelled: the source has already been
        // debited at ship time, and undoing that requires a returns process,
        // not a status flag.
        if (in_array($warehouseTransfer->status, [TransferStatus::Shipped, TransferStatus::Received, TransferStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a warehouse transfer that is '{$warehouseTransfer->status->value}'.",
            ]);
        }

        $old = $warehouseTransfer->only(['status']);
        $warehouseTransfer->forceFill(['status' => TransferStatus::Cancelled])->save();

        $this->audit->record('transfer.cancel', 'warehouse_transfer', $warehouseTransfer->id, $old, ['status' => 'cancelled'], $warehouseTransfer->company_id);

        return $this->success(new WarehouseTransferResource($warehouseTransfer->fresh()->load('items')), 'Warehouse transfer cancelled');
    }

    private function syncItems(WarehouseTransfer $transfer, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $transfer->items()->delete();

        $transfer->items()->createMany(
            collect($items)->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'unit_id' => $item['unit_id'],
                'quantity' => (string) $item['quantity'],
                'quantity_received' => '0',
            ])->all()
        );
    }

    /**
     * Refuse to ship more than a location holds, unless the product opts in to
     * going negative.
     */
    private function guardAvailableStock(WarehouseTransfer $transfer): void
    {
        $where = $this->sourceWhere($transfer);

        $transfer->items->each(function ($item) use ($where) {
            if ($item->product->allow_negative_stock) {
                return;
            }

            $onHand = $this->inventory->onHand(
                $item->product_id,
                $item->product_variant_id,
                $where['warehouse_id'],
                $where['location_id']
            );

            if (bccomp((string) $item->quantity, $onHand, 6) > 0) {
                throw ValidationException::withMessages([
                    'items' => "Item {$item->product->sku}: transfer quantity {$item->quantity} exceeds available stock {$onHand}.",
                ]);
            }
        });
    }

    private function guardReceivable(WarehouseTransfer $warehouseTransfer): void
    {
        if ($warehouseTransfer->status !== TransferStatus::Shipped) {
            throw ValidationException::withMessages([
                'status' => "This warehouse transfer is currently '{$warehouseTransfer->status->value}', so it cannot be received.",
            ]);
        }
    }

    private function guardInMotion(WarehouseTransfer $warehouseTransfer, string $action): void
    {
        if (in_array($warehouseTransfer->status, [TransferStatus::Shipped, TransferStatus::Received, TransferStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'status' => "A warehouse transfer that is '{$warehouseTransfer->status->value}' cannot be {$action}.",
            ]);
        }
    }

    /**
     * The cost basis a line travels at: the source balance's weighted average.
     *
     * Transfer items carry no cost column of their own, and goods must arrive
     * valued at what they left for, so the source average is read here. An
     * outgoing movement ignores it in favour of the same average anyway; an
     * incoming one needs it to keep the destination's valuation honest.
     */
    private function transferCost(WarehouseTransfer $transfer, $item): string
    {
        $balance = StockBalance::query()
            ->where('company_id', $transfer->company_id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('location_id', $transfer->from_location_id)
            ->where('product_id', $item->product_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->where('unit_id', $item->unit_id)
            ->first();

        return (string) ($balance?->average_cost ?? '0');
    }

    /**
     * Ledger location the transfer debits: the source warehouse and its own
     * branch, so the row matches the balance stock was booked onto.
     *
     * @return array{company_id: int, branch_id: ?int, warehouse_id: int, location_id: ?int}
     */
    private function sourceWhere(WarehouseTransfer $transfer): array
    {
        return [
            'company_id' => $transfer->company_id,
            'branch_id' => $transfer->fromWarehouse?->branch_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'location_id' => $transfer->from_location_id,
        ];
    }

    /**
     * Ledger location the transfer credits.
     *
     * @return array{company_id: int, branch_id: ?int, warehouse_id: int, location_id: ?int}
     */
    private function destinationWhere(WarehouseTransfer $transfer): array
    {
        return [
            'company_id' => $transfer->company_id,
            'branch_id' => $transfer->toWarehouse?->branch_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'location_id' => $transfer->to_location_id,
        ];
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(WarehouseTransfer $transfer, TransferStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            TransferStatus::Submitted => TransferStatus::Draft,
            TransferStatus::Approved => TransferStatus::Submitted,
            TransferStatus::Shipped => TransferStatus::Approved,
            TransferStatus::Completed => TransferStatus::Received,
            default => null,
        };

        if ($transfer->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This warehouse transfer is currently '{$transfer->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $transfer->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("transfer.{$action}", 'warehouse_transfer', $transfer->id, null, ['status' => $to->value], $transfer->company_id);
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return WarehouseTransfer::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->from_warehouse_id, fn ($q, $id) => $q->where('from_warehouse_id', $id))
            ->when($request->to_warehouse_id, fn ($q, $id) => $q->where('to_warehouse_id', $id))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'transfer_date', 'created_at']),
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
