<?php

namespace App\Http\Controllers\Api\V1\PurchaseRequests;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest\StorePurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\UpdatePurchaseRequestRequest;
use App\Http\Resources\PurchaseRequestResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseRequestController extends Controller
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
        $this->authorize('viewAny', PurchaseRequest::class);

        $requests = $this->scopedQuery($request)
            ->with(['warehouse:id,company_id,code,name', 'supplier:id,company_id,code,name', 'items:id,purchase_request_id'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(PurchaseRequestResource::collection($requests));
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseRequest::class);
        $this->ensureCompanyAccess($request->company_id, $request->branch_id);

        $purchaseRequest = DB::transaction(function () use ($request) {
            $purchaseRequest = PurchaseRequest::create([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'requested_by' => $request->user()->id,
                'number' => $this->numbering->next('purchase_request', $request->company_id),
                'request_date' => $request->request_date,
                'required_date' => $request->required_date,
                'status' => PurchaseRequestStatus::Draft,
                'notes' => $request->notes,
            ]);

            $this->syncItems($purchaseRequest, $request->items);

            return $purchaseRequest;
        });

        $this->audit->record('purchase_request.create', 'purchase_request', $purchaseRequest->id, null, $purchaseRequest->toArray(), $purchaseRequest->company_id);

        return $this->success(new PurchaseRequestResource($purchaseRequest->load('items')), 'Purchase request created', 201);
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('view', $purchaseRequest);

        return $this->success(new PurchaseRequestResource($purchaseRequest->load(['warehouse', 'supplier', 'items'])));
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('update', $purchaseRequest);
        $this->guardEditable($purchaseRequest, 'updated');

        $old = $purchaseRequest->only(array_keys($request->validated()));

        $purchaseRequest = DB::transaction(function () use ($request, $purchaseRequest) {
            $purchaseRequest->update($request->safe()->except(['items']));

            if ($request->has('items')) {
                $this->syncItems($purchaseRequest, $request->items);
            }

            return $purchaseRequest->fresh();
        });

        $this->audit->record('purchase_request.update', 'purchase_request', $purchaseRequest->id, $old, $purchaseRequest->fresh()->only(array_keys($old)), $purchaseRequest->company_id);

        return $this->success(new PurchaseRequestResource($purchaseRequest->load('items')));
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('delete', $purchaseRequest);
        $this->guardEditable($purchaseRequest, 'deleted');

        $purchaseRequest->delete();

        $this->audit->record('purchase_request.delete', 'purchase_request', $purchaseRequest->id, $purchaseRequest->toArray(), null, $purchaseRequest->company_id);

        return $this->success(null, 'Purchase request deleted');
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('submit', $purchaseRequest);
        $this->transition($purchaseRequest, PurchaseRequestStatus::Submitted, 'submit');

        return $this->success(new PurchaseRequestResource($purchaseRequest->fresh()->load('items')), 'Purchase request submitted');
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('approve', $purchaseRequest);
        $this->transition($purchaseRequest, PurchaseRequestStatus::Approved, 'approve', [
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return $this->success(new PurchaseRequestResource($purchaseRequest->fresh()->load('items')), 'Purchase request approved');
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('reject', $purchaseRequest);
        $this->transition($purchaseRequest, PurchaseRequestStatus::Rejected, 'reject');

        return $this->success(new PurchaseRequestResource($purchaseRequest->fresh()->load('items')), 'Purchase request rejected');
    }

    /**
     * Spawn a draft purchase order from an approved request.
     *
     * Each line carries its own conversion ledger, so an item that has already
     * been fully converted is skipped; a request with nothing left to convert
     * is not convertible at all.
     */
    public function convert(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('convert', $purchaseRequest);

        $request->validate([
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $purchaseRequest->company_id),
            ],
        ]);

        if ($purchaseRequest->status !== PurchaseRequestStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => "A {$purchaseRequest->status->value} purchase request cannot be converted; only an approved request can spawn a purchase order.",
            ]);
        }

        // A rejected request is never convertible, and a request whose lines
        // are all fully consumed has nothing left to order.
        $remaining = $purchaseRequest->items->filter(
            fn ($item) => bccomp((string) $item->quantity, (string) $item->quantity_converted, 6) > 0
        );

        if ($remaining->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Every item on this purchase request has already been fully converted.',
            ]);
        }

        $supplierId = $request->supplier_id ?: $purchaseRequest->supplier_id;

        if (! $supplierId) {
            throw ValidationException::withMessages([
                'supplier_id' => 'A supplier is required to convert this purchase request.',
            ]);
        }

        $purchaseOrder = DB::transaction(function () use ($purchaseRequest, $remaining, $supplierId) {
            $purchaseOrder = PurchaseOrder::create([
                'company_id' => $purchaseRequest->company_id,
                'branch_id' => $purchaseRequest->branch_id,
                'warehouse_id' => $purchaseRequest->warehouse_id,
                'supplier_id' => $supplierId,
                'purchase_request_id' => $purchaseRequest->id,
                'number' => $this->numbering->next('purchase_order', $purchaseRequest->company_id),
                'order_date' => now()->toDateString(),
                'expected_date' => $purchaseRequest->required_date,
                'payment_terms' => 0,
                'currency' => 'IDR',
                'status' => PurchaseOrderStatus::Draft,
                'notes' => "Converted from {$purchaseRequest->number}",
            ]);

            // Snapshot the products once so every line reads the same cost and tax.
            $products = Product::query()
                ->whereIn('id', $remaining->pluck('product_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($remaining as $item) {
                $product = $products->get($item->product_id);
                $taxRate = $product?->tax_id ? (string) $product->tax->rate : '0';

                $purchaseOrder->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'unit_id' => $item->unit_id,
                    'tax_id' => $product?->tax_id,
                    'description' => $product?->name,
                    'quantity' => bcsub((string) $item->quantity, (string) $item->quantity_converted, 6),
                    'quantity_received' => '0',
                    // The cost price is a starting point for the draft; the tax
                    // rate is snapshotted so a later rate change cannot rewrite
                    // this order's history.
                    'unit_price' => (string) ($product?->cost_price ?? '0'),
                    'discount' => '0',
                    'discount_type' => 'amount',
                    'tax_rate' => $taxRate,
                ]);

                // The line is consumed by this conversion.
                $item->forceFill([
                    'quantity_converted' => (string) $item->quantity,
                ])->save();
            }

            $purchaseRequest->forceFill([
                'status' => PurchaseRequestStatus::Converted,
                'converted_at' => now(),
            ])->save();

            // The new order's totals are derived, never copied.
            $this->calculation->applyTotals($purchaseOrder);

            return $purchaseOrder;
        });

        $this->audit->record('purchase_request.convert', 'purchase_request', $purchaseRequest->id, null, ['purchase_order_id' => $purchaseOrder->id], $purchaseRequest->company_id);

        return $this->success(new PurchaseRequestResource($purchaseRequest->fresh()->load(['items', 'purchaseOrders'])), 'Purchase request converted to purchase order');
    }

    /**
     * Write the request's lines, consuming any prior conversion state.
     */
    private function syncItems(PurchaseRequest $purchaseRequest, ?array $items): void
    {
        if ($items === null) {
            return;
        }

        $purchaseRequest->items()->delete();

        $purchaseRequest->items()->createMany(
            collect($items)->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'unit_id' => $item['unit_id'],
                'quantity' => (string) $item['quantity'],
                'quantity_converted' => '0',
                'notes' => $item['notes'] ?? null,
            ])->all()
        );
    }

    /**
     * Advance one step of the state machine, refusing anything out of order.
     */
    private function transition(PurchaseRequest $purchaseRequest, PurchaseRequestStatus $to, string $action, array $with = []): void
    {
        $expected = match ($to) {
            PurchaseRequestStatus::Submitted => PurchaseRequestStatus::Draft,
            PurchaseRequestStatus::Approved, PurchaseRequestStatus::Rejected => PurchaseRequestStatus::Submitted,
            default => null,
        };

        if ($purchaseRequest->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "This purchase request is currently '{$purchaseRequest->status->value}', so it cannot be {$action} (expected '{$expected?->value}').",
            ]);
        }

        $purchaseRequest->forceFill(array_merge(['status' => $to], $with))->save();

        $this->audit->record("purchase_request.{$action}", 'purchase_request', $purchaseRequest->id, null, ['status' => $to->value], $purchaseRequest->company_id);
    }

    /**
     * Only an open request may be edited or deleted; once submitted it belongs
     * to the approval workflow.
     */
    private function guardEditable(PurchaseRequest $purchaseRequest, string $action): void
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "A {$purchaseRequest->status->value} purchase request cannot be {$action}.",
            ]);
        }
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return PurchaseRequest::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $search) => $q->where('number', 'like', "%{$search}%"))
            ->when(
                $request->sort && in_array($request->sort, ['number', 'request_date', 'created_at']),
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
