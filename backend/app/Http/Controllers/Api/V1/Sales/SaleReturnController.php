<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\SaleReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales returns: goods coming back, and the slips that record it.
 *
 * The routes follow the transaction rather than a resource CRUD. A return is
 * raised against a sale (`POST /sales/{sale}/returns`) because the sale is what it
 * reverses, and it is read either on its own or as a sale's history. There is no
 * PUT and no DELETE: a return that was wrong is corrected by posting another one,
 * which is what keeps the stock ledger and the slips agreeing.
 */
class SaleReturnController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected SaleReturnService $returns,
        protected BusinessContext $context
    ) {}

    /**
     * The returns list, newest first, filterable the ways a reconciliation needs.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SaleReturn::class);

        $filters = $request->validate([
            'sale_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(SaleReturnStatus::class)],
            'customer_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $returns = SaleReturn::query()
            ->visibleTo($request->user())
            ->when(
                $this->context->companyId(),
                fn (Builder $q) => $q->where('company_id', $this->context->companyId())
            )
            ->when($filters['sale_id'] ?? null, fn (Builder $q, $id) => $q->where('sale_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('return_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('return_date', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%';

                $q->where('number', 'like', $like);
            })
            ->with(['sale:id,number,grand_total,currency', 'returnedBy:id,name'])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated(SaleReturnResource::collection($returns));
    }

    /**
     * The returns already raised against one sale.
     */
    public function indexForSale(Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        $returns = $sale->returns()
            ->with(['items', 'returnedBy:id,name'])
            ->orderByDesc('id')
            ->get();

        return $this->success(SaleReturnResource::collection($returns)->resolve());
    }

    /**
     * Receive goods back and post them to stock, in one transaction.
     */
    public function store(StoreSaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $this->authorize('returnSale', $sale);

        $return = $this->returns->store($sale, $request->user(), $request->validated());

        return $this->success(
            $this->present($return),
            "Return {$return->number} recorded against sale {$sale->number}.",
            201
        );
    }

    public function show(SaleReturn $saleReturn): JsonResponse
    {
        $this->authorize('view', $saleReturn);

        return $this->success($this->present($saleReturn));
    }

    private function present(SaleReturn $saleReturn): array
    {
        return (new SaleReturnResource($saleReturn->load([
            'items',
            'sale:id,number,grand_total,currency',
            'returnedBy:id,name',
            'refunds.allocations',
        ])))->resolve();
    }
}
