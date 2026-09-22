<?php

namespace App\Http\Controllers\Api\V1\Refunds;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Refunds\RejectRefundRequest;
use App\Http\Requests\Refunds\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\RefundService;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Refunds: money going back to a customer, and who signed for it.
 *
 * The endpoints follow the refund's own lifecycle rather than a resource CRUD,
 * because every step after creation is a decision with a name on it: /approve,
 * /reject, /process, /complete and /fail. There is no PUT and no DELETE — a refund
 * is never edited, and when one goes wrong it is failed and raised again, which is
 * what keeps the tenders it wrote down trustworthy.
 *
 * Everything that decides a figure — how much is still refundable, which payments
 * the money comes off, whether the threshold refers it to a manager — is computed
 * by `RefundService`; these actions only hand it what a human is allowed to supply.
 */
class RefundController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected RefundService $refunds,
        protected BusinessContext $context
    ) {}

    /**
     * The refunds list, filterable by status, sale, method and date.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Refund::class);

        $filters = $request->validate([
            'sale_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'method' => ['nullable', Rule::enum(RefundMethod::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $refunds = Refund::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn (Builder $q) => $q->where('company_id', $this->context->companyId())
            )
            ->when($filters['sale_id'] ?? null, fn (Builder $q, $id) => $q->where('sale_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['method'] ?? null, fn (Builder $q, $method) => $q->where('method', $method))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('requested_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('requested_at', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%';

                $q->where('number', 'like', $like);
            })
            ->with(['sale:id,number,grand_total,currency', 'requestedBy:id,name'])
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated(RefundResource::collection($refunds));
    }

    /**
     * The refunds raised against one sale.
     */
    public function indexForSale(Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        $refunds = $sale->refunds()
            ->with(['allocations.payment', 'requestedBy:id,name'])
            ->orderByDesc('id')
            ->get();

        return $this->success(RefundResource::collection($refunds)->resolve());
    }

    /**
     * Raise a refund. Whether it needs approval is decided by the shop's threshold.
     */
    public function store(StoreRefundRequest $request, Sale $sale): JsonResponse
    {
        // Reading the sale proves the caller can reach its company; raising the
        // refund is then its own permission.
        $this->authorize('view', $sale);
        $this->authorize('create', Refund::class);

        $refund = $this->refunds->store($sale, $request->user(), $request->validated());

        return $this->success(
            $this->present($refund),
            "Refund {$refund->number} raised as {$refund->status->label()}.",
            201
        );
    }

    public function show(Refund $refund): JsonResponse
    {
        $this->authorize('view', $refund);

        return $this->success($this->present($refund));
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $this->authorize('approve', $refund);

        $input = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = $this->refunds->approve($refund, $request->user(), $input);

        return $this->success($this->present($refund), "Refund {$refund->number} approved.");
    }

    public function reject(RejectRefundRequest $request, Refund $refund): JsonResponse
    {
        $this->authorize('reject', $refund);

        $refund = $this->refunds->reject($refund, $request->user(), $request->validated());

        return $this->success($this->present($refund), "Refund {$refund->number} rejected.");
    }

    public function process(Request $request, Refund $refund): JsonResponse
    {
        $this->authorize('process', $refund);

        $refund = $this->refunds->process($refund, $request->user());

        return $this->success($this->present($refund), "Refund {$refund->number} is processing.");
    }

    /**
     * Pay the refund out: the step that writes the sale's tenders down.
     */
    public function complete(Request $request, Refund $refund): JsonResponse
    {
        $this->authorize('complete', $refund);

        $input = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:128'],
        ]);

        $refund = $this->refunds->complete($refund, $request->user(), $input);

        return $this->success($this->present($refund), "Refund {$refund->number} completed.");
    }

    public function fail(Request $request, Refund $refund): JsonResponse
    {
        $this->authorize('fail', $refund);

        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = $this->refunds->fail($refund, $request->user(), $input);

        return $this->success($this->present($refund), "Refund {$refund->number} marked failed.");
    }

    private function present(Refund $refund): array
    {
        return (new RefundResource($refund->load([
            'allocations.payment',
            'sale:id,number,grand_total,currency',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'processedBy:id,name',
        ])))->resolve();
    }
}
