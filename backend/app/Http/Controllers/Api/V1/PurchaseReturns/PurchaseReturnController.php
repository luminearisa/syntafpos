<?php

namespace App\Http\Controllers\Api\V1\PurchaseReturns;

use App\Enums\MovementType;
use App\Enums\PurchaseReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReturn\StorePurchaseReturnRequest;
use App\Http\Requests\PurchaseReturn\UpdatePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Branch;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseReturn;
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

class PurchaseReturnController extends Controller
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
        $this->authorize('viewAny', PurchaseReturn::class);

        $returns = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'supplier:id,company_id,name', 'items:id,purchase_return_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(PurchaseReturnResource::collection($returns));
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseReturn::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $return = DB::transaction(function () use ($request) {
            $return = PurchaseReturn::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'purchase_order_id' => $request->purchase_order_id,
                'goods_receipt_id' => $request->goods_receipt_id,
                'number' => $this->numbering->next('purchase_return', $request->company_id),
                'return_date' => $request->return_date,
                'status' => PurchaseReturnStatus::Draft,
                'total_amount' => '0',
                // Writing null explicitly would defeat the column's 'other'
                // default and trip the NOT NULL constraint.
                'reason' => $request->reason ?? 'other',
                'notes' => $request->notes,
            ]);

            $this->syncItems($return, $request->items);

            return $return;
        });

        $this->audit->record('purchase_return.create', 'purchase_return', $return->id, null, $return->toArray(), $return->company_id);

        return $this->success(new PurchaseReturnResource($return->load('items')), 'Purchase return created', 201);
    }

    public function show(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('view', $purchaseReturn);

        return $this->success(new PurchaseReturnResource($purchaseReturn->load(['warehouse', 'supplier', 'items'])));
    }

    public function update(UpdatePurchaseReturnRequest $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('update', $purchaseReturn);
        $this->guardNotPosted($purchaseReturn, 'updated');

        $old = $purchaseReturn->only(array_keys($request->validated()));

        $purchaseReturn = DB::transaction(function () use ($request, $purchaseReturn) {
            $purchaseReturn->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($purchaseReturn, $request->items);
            }

            return $purchaseReturn->fresh();
        });

        $this->audit->record('purchase_return.update', 'purchase_return', $purchaseReturn->id, $old, $purchaseReturn->fresh()->only(array_keys($old)), $purchaseReturn->company_id);

        return $this->success(new PurchaseReturnResource($purchaseReturn->load('items')));
    }

    public function destroy(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('delete', $purchaseReturn);
        $this->guardNotPosted($purchaseReturn, 'deleted');

        $purchaseReturn->delete();

        $this->audit->record('purchase_return.delete', 'purchase_return', $purchaseReturn->id, $purchaseReturn->toArray(), null, $purchaseReturn->company_id);

        return $this->success(null, 'Purchase return deleted');
    }

    public function post(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('post', $purchaseReturn);

        // The status flip and every stock-reducing movement share one
        // transaction, so a balance that cannot cover the return leaves the
        // document a draft with no ledger rows written.
        try {
            DB::transaction(function () use ($purchaseReturn, $request) {
                $this->transition($purchaseReturn, PurchaseReturnStatus::Posted, 'post', [
                    'posted_at' => now(),
                    'returned_by' => $request->user()->id,
                ]);

                $purchaseReturn->load('items');

                $where = [
                    'company_id' => $purchaseReturn->company_id,
                    'branch_id' => $purchaseReturn->branch_id,
                    'warehouse_id' => $purchaseReturn->warehouse_id,
                    'location_id' => null,
                ];

                $total = '0';

                $purchaseReturn->items->each(function ($item) use ($purchaseReturn, $where, $request, &$total) {
                    $quantity = (string) $item->quantity;

                    // An empty line moves nothing and counts nothing.
                    if (bccomp($quantity, '0', 6) === 0) {
                        $item->forceFill(['total_amount' => '0'])->save();

                        return;
                    }

                    $this->inventory->move(
                        [
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'unit_id' => $item->unit_id,
                            'quantity' => $quantity,
                        ],
                        // Stock-reducing by sign: the engine refuses to drive a
                        // product negative unless the product allows it, which
                        // is what keeps a return honest against on hand.
                        MovementType::PurchaseReturn,
                        $where,
                        $purchaseReturn,
                        (string) $item->unit_cost,
                        $request->user()->id,
                        "Purchase return {$purchaseReturn->number}"
                    );

                    $lineTotal = DecimalMath::mul($quantity, (string) $item->unit_cost);

                    $item->forceFill(['total_amount' => $lineTotal])->save();

                    $total = DecimalMath::add($total, $lineTotal);
                });

                // The document total is recomputed server side from the lines;
                // a client-provided figure is never trusted onto the ledger.
                $purchaseReturn->forceFill(['total_amount' => $total])->save();
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->success(new PurchaseReturnResource($purchaseReturn->fresh()->load('items')), 'Purchase return posted');
    }

    /**
     * Write the return lines, valuing each one unless a cost is stated.
     *
     * A line linked to a receipt item inherits its cost, since goods must go
     * back out at the value they arrived at; otherwise the stated cost stands.
     */
    private function syncItems(PurchaseReturn $return, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $receiptItems = GoodsReceiptItem::query()
            ->whereIn('id', collect($items)->map(fn (array $item) => $item['goods_receipt_item_id'] ?? null)->filter()->all())
            ->get()
            ->keyBy('id');

        $return->items()->delete();

        $return->items()->createMany(
            collect($items)->map(function (array $item) use ($receiptItems) {
                /** @var GoodsReceiptItem|null $receiptItem */
                $receiptItem = $receiptItems[$item['goods_receipt_item_id'] ?? null] ?? null;

                return [
                    'goods_receipt_item_id' => $receiptItem?->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'],
                    'quantity' => (string) $item['quantity'],
                    'unit_cost' => (string) ($item['unit_cost'] ?? ($receiptItem?->unit_cost ?? '0')),
                    'total_amount' => DecimalMath::mul((string) $item['quantity'], (string) ($item['unit_cost'] ?? ($receiptItem?->unit_cost ?? '0'))),
                    'notes' => $item['notes'] ?? null,
                ];
            })->all()
        );
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(PurchaseReturn $return, PurchaseReturnStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            PurchaseReturnStatus::Posted => PurchaseReturnStatus::Draft,
            default => null,
        };

        if ($return->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This purchase return is currently '{$return->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $return->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("purchase_return.{$action}", 'purchase_return', $return->id, null, ['status' => $to->value], $return->company_id);
    }

    private function guardNotPosted(PurchaseReturn $return, string $action): void
    {
        if ($return->status === PurchaseReturnStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => "A posted purchase return cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return PurchaseReturn::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->goods_receipt_id, fn ($q, $id) => $q->where('goods_receipt_id', $id))
            ->when($request->purchase_order_id, fn ($q, $id) => $q->where('purchase_order_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'return_date', 'created_at']),
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
