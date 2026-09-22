<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutSaleRequest;
use App\Http\Requests\Pos\CompleteSaleRequest;
use App\Http\Requests\Sales\VoidSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\PosCart;
use App\Models\Sale;
use App\Services\SaleReceiptService;
use App\Services\SaleService;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales: the documents a till produces, and the only place one is read from.
 *
 * The endpoints follow the transaction rather than a resource CRUD, because a
 * posted sale is not editable: /complete settles it and posts the stock, /cancel
 * withdraws it, /receipt prints it. There is no update or delete route at all.
 */
class SaleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected SaleService $sales,
        protected SaleReceiptService $receipts,
        protected BusinessContext $context
    ) {}

    /**
     * Sales list, newest first, filterable by status, customer, register and
     * date — the four ways a shift gets reconciled.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SaleStatus::class)],
            'customer_id' => ['nullable', 'integer'],
            'register_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sales = Sale::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn (Builder $q) => $q->where('company_id', $this->context->companyId())
            )
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['register_id'] ?? null, fn (Builder $q, $id) => $q->where('register_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('date', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%';

                // Number, customer name and the sale's own line snapshot: what a
                // cashier types when looking for a receipt.
                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhereHas('items', fn (Builder $items) => $items->where('product_name', 'like', $like));
                });
            })
            // The columns a row's payment summary renders — not the full tender,
            // which only the document itself needs. Register and cashier are here
            // because a shift is reconciled per till and per person.
            ->with([
                'payments:id,sale_id,number,channel,method_name,amount,tendered,change,refunded_amount,currency,status,reference,paid_at,created_at',
                'register:id,code,name',
                'cashier:id,name',
            ])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated(SaleResource::collection($sales));
    }

    /**
     * Checkout. Creates the sale from a cart, posts stock and takes payment in
     * one transaction; see SaleService::checkout().
     */
    public function store(CheckoutSaleRequest $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $input = $request->validated();

        $cart = PosCart::query()
            ->visibleTo($request->user())
            ->where('company_id', $this->context->companyId())
            ->find($input['cart_id']);

        if (! $cart) {
            return $this->error('That cart no longer exists.', 404);
        }

        $sale = $this->sales->checkout($cart, $request->user(), $input);

        return $this->success(
            $this->present($sale),
            "Sale {$sale->number} created.",
            201
        );
    }

    public function show(Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        return $this->success($this->present($sale));
    }

    /**
     * Take further payment and, once the ticket is settled, post stock and close
     * it. A short tender leaves it Partially Paid rather than failing.
     */
    public function complete(CompleteSaleRequest $request, Sale $sale): JsonResponse
    {
        $this->authorize('complete', $sale);

        $sale = $this->sales->complete($sale, $request->user(), $request->validated());

        return $this->success($this->present($sale), "Sale {$sale->number} is {$sale->status->label()}.");
    }

    /**
     * Withdraw the sale: stock goes back through the ledger, tenders are voided.
     */
    public function cancel(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('cancel', $sale);

        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = $this->sales->cancel($sale, $request->user(), $input);

        return $this->success($this->present($sale), "Sale {$sale->number} cancelled.");
    }

    /**
     * Void an open transaction, with a mandatory reason.
     *
     * The formal counterpart to cancel: same reversal of stock and tenders, but
     * gated by `sales.void`, requiring a reason, and refusing a sale that has
     * already completed — which must be reversed with a return and a refund
     * instead. Nothing is deleted; the sale keeps its rows and gains an audit
     * entry naming who voided it, when and why.
     */
    public function void(VoidSaleRequest $request, Sale $sale): JsonResponse
    {
        $this->authorize('void', $sale);

        $sale = $this->sales->void($sale, $request->user(), $request->validated());

        return $this->success($this->present($sale), "Sale {$sale->number} voided.");
    }

    /**
     * The printable document.
     *
     * Returns the receipt's own data plus the rendered HTML for one of three
     * paper widths — 58mm and 80mm till rolls, or an A4 invoice — so the client
     * opens a print window without rebuilding the layout, and the same figures
     * come from the same place either way.
     */
    public function receipt(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        $validated = $request->validate([
            'width' => ['nullable', 'in:58,80,a4'],
        ]);

        $width = $validated['width'] ?? '80';

        return $this->success([
            'width' => $width,
            'sale' => $this->present($sale),
            'html' => $this->receipts->render($sale, $width),
        ]);
    }

    private function present(Sale $sale): array
    {
        return (new SaleResource($sale->load([
            'items',
            'payments.receivedBy:id,name',
            // Phase 3.5 — the goods returned and the money given back, so the
            // invoice can show what has already been reversed against it.
            'returns.items',
            'refunds.allocations.payment',
            'company:id,name,code,legal_name,phone,address,city,province,country,postal_code,tax_number,currency',
            'register:id,code,name',
            'warehouse:id,code,name',
            'cashier:id,name',
        ])))->resolve();
    }
}
