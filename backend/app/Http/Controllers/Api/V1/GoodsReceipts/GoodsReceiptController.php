<?php

namespace App\Http\Controllers\Api\V1\GoodsReceipts;

use App\Enums\GoodsReceiptStatus;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoodsReceipt\StoreGoodsReceiptRequest;
use App\Http\Requests\GoodsReceipt\UpdateGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrderItem;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\NumberingService;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
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
        $this->authorize('viewAny', GoodsReceipt::class);

        $receipts = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'supplier:id,company_id,name', 'items:id,goods_receipt_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(GoodsReceiptResource::collection($receipts));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', GoodsReceipt::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $receipt = DB::transaction(function () use ($request) {
            $receipt = GoodsReceipt::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'purchase_order_id' => $request->purchase_order_id,
                'number' => $this->numbering->next('goods_receipt', $request->company_id),
                'receipt_date' => $request->receipt_date,
                'status' => GoodsReceiptStatus::Draft,
                'notes' => $request->notes,
            ]);

            $this->syncItems($receipt, $request->items);

            return $receipt;
        });

        $this->audit->record('goods_receipt.create', 'goods_receipt', $receipt->id, null, $receipt->toArray(), $receipt->company_id);

        return $this->success(new GoodsReceiptResource($receipt->load('items')), 'Goods receipt created', 201);
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->authorize('view', $goodsReceipt);

        return $this->success(new GoodsReceiptResource($goodsReceipt->load(['warehouse', 'supplier', 'items'])));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->authorize('update', $goodsReceipt);
        $this->guardNotPosted($goodsReceipt, 'updated');

        $old = $goodsReceipt->only(array_keys($request->validated()));

        $goodsReceipt = DB::transaction(function () use ($request, $goodsReceipt) {
            $goodsReceipt->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($goodsReceipt, $request->items);
            }

            return $goodsReceipt->fresh();
        });

        $this->audit->record('goods_receipt.update', 'goods_receipt', $goodsReceipt->id, $old, $goodsReceipt->fresh()->only(array_keys($old)), $goodsReceipt->company_id);

        return $this->success(new GoodsReceiptResource($goodsReceipt->load('items')));
    }

    public function destroy(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->authorize('delete', $goodsReceipt);
        $this->guardNotPosted($goodsReceipt, 'deleted');

        $goodsReceipt->delete();

        $this->audit->record('goods_receipt.delete', 'goods_receipt', $goodsReceipt->id, $goodsReceipt->toArray(), null, $goodsReceipt->company_id);

        return $this->success(null, 'Goods receipt deleted');
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->authorize('post', $goodsReceipt);

        // The status flip, every stock movement and the purchase order's
        // received progress share one transaction, so a rejected movement
        // leaves the receipt a draft with no ledger rows written.
        try {
            DB::transaction(function () use ($goodsReceipt, $request) {
                $this->transition($goodsReceipt, GoodsReceiptStatus::Posted, 'post', [
                    'posted_at' => now(),
                    'received_by' => $request->user()->id,
                ]);

                $goodsReceipt->load('items');

                $where = [
                    'company_id' => $goodsReceipt->company_id,
                    'branch_id' => $goodsReceipt->branch_id,
                    'warehouse_id' => $goodsReceipt->warehouse_id,
                    'location_id' => null,
                ];

                $progress = [];

                $goodsReceipt->items->each(function ($item) use ($goodsReceipt, $where, $request, &$progress) {
                    $quantity = (string) $item->quantity_received;

                    // An empty line books nothing, so a line held back from a
                    // partial delivery does not produce a zero-quantity movement.
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
                        // The receipt's cost becomes the new weighted average
                        // inside the engine; the movement type only carries the
                        // direction.
                        MovementType::Purchase,
                        $where,
                        $goodsReceipt,
                        (string) $item->unit_cost,
                        $request->user()->id,
                        "Goods receipt {$goodsReceipt->number}"
                    );

                    // Roll the purchase order line forward, keyed so a line is
                    // only accumulated once even if the receipt repeats it.
                    if ($item->purchase_order_item_id) {
                        $progress[$item->purchase_order_item_id] = DecimalMath::add(
                            $progress[$item->purchase_order_item_id] ?? '0',
                            $quantity
                        );
                    }
                });

                foreach ($progress as $poItemId => $quantity) {
                    /** @var PurchaseOrderItem $poItem */
                    $poItem = PurchaseOrderItem::query()->lockForUpdate()->find($poItemId);

                    if (! $poItem) {
                        continue;
                    }

                    $poItem->forceFill([
                        'quantity_received' => DecimalMath::add((string) $poItem->quantity_received, $quantity),
                    ])->save();
                }

                // The order's own status tracks the ledger, recomputed from its
                // lines now that receiving has landed.
                $goodsReceipt->load('purchaseOrder')->purchaseOrder?->markReceivedProgress();
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->success(new GoodsReceiptResource($goodsReceipt->fresh()->load('items')), 'Goods receipt posted');
    }

    /**
     * Write the receipt lines, deriving what the client is not authoritative for.
     *
     * quantity_ordered and unit_price are copied from the purchase order line
     * when one is linked, and unit_cost falls back to unit_price: the receipt
     * is valued at what was actually paid unless a cost is stated explicitly.
     */
    private function syncItems(GoodsReceipt $receipt, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $poItems = PurchaseOrderItem::query()
            ->whereIn('id', collect($items)->map(fn (array $item) => $item['purchase_order_item_id'] ?? null)->filter()->all())
            ->get()
            ->keyBy('id');

        $receipt->items()->delete();

        $receipt->items()->createMany(
            collect($items)->map(function (array $item) use ($poItems) {
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $poItems[$item['purchase_order_item_id'] ?? null] ?? null;

                $unitPrice = (string) ($item['unit_price'] ?? ($poItem?->net_price ?? '0'));

                return [
                    'purchase_order_item_id' => $poItem?->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'],
                    'quantity_ordered' => $poItem
                        ? (string) $poItem->quantity
                        : (string) ($item['quantity_ordered'] ?? '0'),
                    'quantity_received' => (string) $item['quantity_received'],
                    'unit_price' => $unitPrice,
                    'unit_cost' => (string) ($item['unit_cost'] ?? $unitPrice),
                    'notes' => $item['notes'] ?? null,
                ];
            })->all()
        );
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(GoodsReceipt $receipt, GoodsReceiptStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            GoodsReceiptStatus::Posted => GoodsReceiptStatus::Draft,
            default => null,
        };

        if ($receipt->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This goods receipt is currently '{$receipt->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $receipt->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("goods_receipt.{$action}", 'goods_receipt', $receipt->id, null, ['status' => $to->value], $receipt->company_id);
    }

    private function guardNotPosted(GoodsReceipt $receipt, string $action): void
    {
        if ($receipt->status === GoodsReceiptStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => "A posted goods receipt cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return GoodsReceipt::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->purchase_order_id, fn ($q, $id) => $q->where('purchase_order_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'receipt_date', 'created_at']),
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
