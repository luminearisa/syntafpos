<?php

namespace App\Http\Controllers\Api\V1\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\SupplierSummaryResource;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = $this->scopedQuery($request)
            ->paginate($request->integer('per_page', 20));

        return $this->paginated(SupplierResource::collection($suppliers));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);
        $this->ensureCompanyAccess($request->company_id);

        $supplier = Supplier::create($request->validated());

        $this->audit->record('supplier.create', 'supplier', $supplier->id, null, $request->validated(), $supplier->company_id);

        return $this->success(new SupplierResource($supplier), 'Supplier created', 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return $this->success(new SupplierResource($supplier));
    }

    /**
     * Purchase transaction summary of one supplier.
     *
     * Aggregated live from goods receipts and purchase returns, never stored:
     * a receipt has no total column, so its value is derived from its lines
     * (quantity_received x unit_cost) and returns read their total_amount.
     * Only posted documents count; a draft is a working copy, not a transaction.
     */
    public function summary(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return $this->success(new SupplierSummaryResource($this->computeSummary($supplier)), 'Supplier transaction summary');
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);

        $old = $supplier->only(array_keys($request->validated()));

        $supplier->update($request->validated());

        $this->audit->record('supplier.update', 'supplier', $supplier->id, $old, $supplier->fresh()->only(array_keys($old)), $supplier->company_id);

        return $this->success(new SupplierResource($supplier->fresh()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        $supplier->delete();

        $this->audit->record('supplier.delete', 'supplier', $supplier->id, $supplier->toArray(), null, $supplier->company_id);

        return $this->success(null, 'Supplier deleted');
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Supplier::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, function ($q, string $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('supplier_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->sort && in_array($request->sort, ['name', 'supplier_code', 'created_at']),
                fn ($q) => $q->orderBy($request->sort, $request->direction === 'asc' ? 'asc' : 'desc'),
                fn ($q) => $q->latest()
            );
    }

    /**
     * Sum the supplier's real purchasing rows into a summary array.
     *
     * Two grouped queries instead of a join, so receipts without lines still
     * count toward purchase_count and never vanish from the totals.
     *
     * @return array{
     *     supplier_id: int,
     *     supplier_code: string,
     *     supplier_name: string,
     *     payment_terms: int,
     *     total_purchase: string,
     *     total_return: string,
     *     paid: string,
     *     outstanding: string,
     *     purchase_count: int,
     *     last_purchase_date: ?string,
     *     due_date: ?string
     * }
     */
    private function computeSummary(Supplier $supplier): array
    {
        $receipts = DB::table('goods_receipts')
            ->leftJoin('goods_receipt_items', 'goods_receipt_items.goods_receipt_id', '=', 'goods_receipts.id')
            ->where('goods_receipts.supplier_id', $supplier->id)
            ->where('goods_receipts.company_id', $supplier->company_id)
            ->where('goods_receipts.status', 'posted')
            ->whereNull('goods_receipts.deleted_at')
            ->selectRaw(
                'count(distinct goods_receipts.id) as purchase_count, '
                .'coalesce(sum(goods_receipt_items.quantity_received * goods_receipt_items.unit_cost), 0) as total_purchase, '
                .'max(goods_receipts.receipt_date) as last_purchase_date'
            )
            ->first();

        $totalReturn = (string) DB::table('purchase_returns')
            ->where('supplier_id', $supplier->id)
            ->where('company_id', $supplier->company_id)
            ->where('status', 'posted')
            ->whereNull('deleted_at')
            ->sum('total_amount');

        $totalPurchase = (string) ($receipts->total_purchase ?? 0);
        // MySQL answers max() on a DATE column with a full datetime string, so
        // the value is trimmed back to the date the column actually holds.
        $lastPurchaseDate = $receipts->last_purchase_date
            ? Carbon::parse($receipts->last_purchase_date)->toDateString()
            : null;

        return [
            'supplier_id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->name,
            'payment_terms' => (int) $supplier->payment_terms,
            'total_purchase' => $totalPurchase,
            'total_return' => $totalReturn,
            // No payment engine exists in Phase 2; when one lands it will be
            // summed here rather than stored as a balance.
            'paid' => '0',
            'outstanding' => bcsub($totalPurchase, $totalReturn, 4),
            'purchase_count' => (int) ($receipts->purchase_count ?? 0),
            'last_purchase_date' => $lastPurchaseDate,
            'due_date' => $this->dueDate($lastPurchaseDate, (int) $supplier->payment_terms),
        ];
    }

    /**
     * Due date of the most recent purchase: its receipt date plus the
     * supplier's payment terms. Nothing is owed without a purchase, and no
     * date applies without terms.
     */
    private function dueDate(?string $lastPurchaseDate, int $paymentTerms): ?string
    {
        if ($lastPurchaseDate === null || $paymentTerms <= 0) {
            return null;
        }

        return date('Y-m-d', strtotime("+{$paymentTerms} days", strtotime($lastPurchaseDate)));
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
