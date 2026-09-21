<?php

namespace App\Http\Controllers\Api\V1\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Tax;
use App\Services\AuditService;
use App\Services\NumberingService;
use App\Services\PurchaseCalculationService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context,
        protected NumberingService $numbering,
        protected PurchaseCalculationService $calculation
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $orders = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'supplier:id,company_id,code,name', 'items:id,purchase_order_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(PurchaseOrderResource::collection($orders));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $order = DB::transaction(function () use ($request) {
            $order = PurchaseOrder::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'purchase_request_id' => null,
                'number' => $this->numbering->next('purchase_order', $request->company_id),
                'order_date' => $request->order_date,
                'expected_date' => $request->expected_date,
                'payment_terms' => $request->payment_terms ?? 0,
                // The currency is snapshotted on the header at write time.
                'currency' => $request->currency ?? 'IDR',
                'status' => PurchaseOrderStatus::Draft,
                'discount_total' => (string) ($request->discount_total ?? '0'),
                'shipping_cost' => (string) ($request->shipping_cost ?? '0'),
                'other_charges' => (string) ($request->other_charges ?? '0'),
                'notes' => $request->notes,
            ]);

            $this->syncItems($order, $request->items);

            // Every money column is derived from the lines, never trusted, and
            // lands in the same transaction so an order and its totals are
            // written together.
            $this->calculation->applyTotals($order);

            return $order;
        });

        $this->audit->record('purchase_order.create', 'purchase_order', $order->id, null, $order->toArray(), $order->company_id);

        return $this->success(new PurchaseOrderResource($order->load('items')), 'Purchase order created', 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return $this->success(new PurchaseOrderResource($purchaseOrder->load(['warehouse', 'supplier', 'items'])));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);
        $this->guardEditable($purchaseOrder, 'updated');

        $old = $purchaseOrder->only(array_keys($request->validated()));

        $purchaseOrder = DB::transaction(function () use ($request, $purchaseOrder) {
            $purchaseOrder->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($purchaseOrder, $request->items);
            }

            $purchaseOrder = $purchaseOrder->fresh();

            // Re-derive the totals: header charges may have moved with the edit.
            $this->calculation->applyTotals($purchaseOrder);

            return $purchaseOrder;
        });

        $this->audit->record('purchase_order.update', 'purchase_order', $purchaseOrder->id, $old, $purchaseOrder->fresh()->only(array_keys($old)), $purchaseOrder->company_id);

        return $this->success(new PurchaseOrderResource($purchaseOrder->load('items')));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('delete', $purchaseOrder);
        $this->guardEditable($purchaseOrder, 'deleted');

        $purchaseOrder->delete();

        $this->audit->record('purchase_order.delete', 'purchase_order', $purchaseOrder->id, $purchaseOrder->toArray(), null, $purchaseOrder->company_id);

        return $this->success(null, 'Purchase order deleted');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('submit', $purchaseOrder);
        $this->transition($purchaseOrder, PurchaseOrderStatus::Submitted, 'submit');

        return $this->success(new PurchaseOrderResource($purchaseOrder->fresh()->load('items')), 'Purchase order submitted');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('approve', $purchaseOrder);
        $this->transition($purchaseOrder, PurchaseOrderStatus::Approved, 'approve', [
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return $this->success(new PurchaseOrderResource($purchaseOrder->fresh()->load('items')), 'Purchase order approved');
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('send', $purchaseOrder);
        $this->transition($purchaseOrder, PurchaseOrderStatus::Sent, 'send', [
            'sent_at' => now(),
        ]);

        return $this->success(new PurchaseOrderResource($purchaseOrder->fresh()->load('items')), 'Purchase order sent');
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('close', $purchaseOrder);
        $this->transition($purchaseOrder, PurchaseOrderStatus::Closed, 'close', [
            'closed_at' => now(),
        ]);

        return $this->success(new PurchaseOrderResource($purchaseOrder->fresh()->load('items')), 'Purchase order closed');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('cancel', $purchaseOrder);
        $this->transition($purchaseOrder, PurchaseOrderStatus::Cancelled, 'cancel');

        return $this->success(new PurchaseOrderResource($purchaseOrder->fresh()->load('items')), 'Purchase order cancelled');
    }

    /**
     * Replace the order's lines, recomputing every money field as they are written.
     *
     * The tax rate is resolved from the item's tax_id when one is given and
     * snapshotted onto the line, so a later rate change cannot rewrite the
     * order's history. Header totals are applied afterwards by applyTotals().
     */
    private function syncItems(PurchaseOrder $purchaseOrder, array $items): void
    {
        $taxes = Tax::query()
            ->whereIn('id', collect($items)->pluck('tax_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $rows = collect($items)->map(function (array $item) use ($taxes): array {
            $tax = ($item['tax_id'] ?? null) ? $taxes->get($item['tax_id']) : null;

            return [
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'unit_id' => $item['unit_id'],
                'tax_id' => $tax?->id,
                'description' => $item['description'] ?? null,
                'quantity' => (string) $item['quantity'],
                'quantity_received' => '0',
                'unit_price' => (string) $item['unit_price'],
                'discount' => (string) ($item['discount'] ?? '0'),
                'discount_type' => (string) ($item['discount_type'] ?? 'amount'),
                // The rate is frozen here, never re-read later.
                'tax_rate' => $tax ? (string) $tax->rate : (string) ($item['tax_rate'] ?? '0'),
            ];
        })->all();

        $computed = $this->calculation->calculateItems(collect($rows));

        foreach ($rows as $index => $row) {
            $rows[$index]['net_price'] = $computed['items'][$index]['net_price'];
            $rows[$index]['tax_amount'] = $computed['items'][$index]['tax_amount'];
            $rows[$index]['subtotal'] = $computed['items'][$index]['subtotal'];
        }

        $purchaseOrder->items()->delete();
        $purchaseOrder->items()->createMany($rows);
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(PurchaseOrder $purchaseOrder, PurchaseOrderStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            PurchaseOrderStatus::Submitted => PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::Approved => PurchaseOrderStatus::Submitted,
            PurchaseOrderStatus::Sent => PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Closed => PurchaseOrderStatus::Received,
            // Only a draft may be cancelled, and only before it is sent.
            PurchaseOrderStatus::Cancelled => PurchaseOrderStatus::Draft,
            default => null,
        };

        if ($purchaseOrder->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This purchase order is currently '{$purchaseOrder->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $purchaseOrder->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("purchase_order.{$action}", 'purchase_order', $purchaseOrder->id, null, ['status' => $to->value], $purchaseOrder->company_id);
    }

    /**
     * Only a draft order may be edited or deleted; once submitted it belongs to
     * the approval workflow.
     */
    private function guardEditable(PurchaseOrder $purchaseOrder, string $action): void
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "A {$purchaseOrder->status->value} purchase order cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return PurchaseOrder::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'order_date', 'created_at']),
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
