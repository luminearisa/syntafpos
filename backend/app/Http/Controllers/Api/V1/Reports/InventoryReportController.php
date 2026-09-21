<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\LowStockResource;
use App\Http\Resources\Reports\StockCardResource;
use App\Http\Resources\Reports\StockMovementResource;
use App\Http\Resources\Reports\StockOpnameSummaryResource;
use App\Http\Resources\Reports\StockSummaryResource;
use App\Http\Resources\Reports\StockValuationResource;
use App\Http\Resources\Reports\WarehouseTransferSummaryResource;
use App\Services\InventoryReportService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory reports (spec §32): read-only aggregations over the stock ledger.
 *
 * No single model is owned here, so there is no policy to consult: every method
 * asks the registered permission gate directly for reports.inventory. All
 * scoping happens inside the service, which never reads a row outside the
 * user's own companies.
 */
class InventoryReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BusinessContext $context,
        protected InventoryReportService $service
    ) {}

    /**
     * Stock position per product, warehouse and unit.
     *
     * Filters: warehouse_id, location_id, category_id, brand_id, product_id and
     * search over the product name and SKUs. Paginated.
     */
    public function stockSummary(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $page = $this->service->stockSummary(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(StockSummaryResource::collection($page), 'Stock summary');
    }

    /**
     * The running ledger of one product: date, reference, in, out, balance and
     * cost per movement, oldest first.
     *
     * product_id is required, because a card without a product is the movement
     * report. The balance is the recorded balance_after, never recomputed.
     */
    public function stockCard(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules([
            'product_id' => ['required', 'integer'],
        ]));

        $page = $this->service->stockCard(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(StockCardResource::collection($page), 'Stock card');
    }

    /**
     * The movement ledger, filtered in SQL on the table's indexes.
     *
     * Filters: product, warehouse, movement type, date range and the polymorphic
     * reference. Paginated, newest first by default.
     */
    public function stockMovements(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $page = $this->service->stockMovements(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(StockMovementResource::collection($page), 'Stock movements');
    }

    /**
     * Inventory-tracked products at or below their reorder point, each with the
     * quantity suggested to replenish.
     */
    public function lowStock(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $page = $this->service->lowStock(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(LowStockResource::collection($page), 'Low stock');
    }

    /**
     * On hand valued at the weighted average cost, one row per balance, with a
     * subtotal per warehouse and a grand total.
     */
    public function stockValuation(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $result = $this->service->stockValuation(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->success([
            'rows' => StockValuationResource::collection($result['rows'])->resolve(),
            'subtotals' => $result['subtotals'],
            'total' => $result['total'],
        ], 'Stock valuation', 200, [
            'pagination' => $this->paginationMeta($result['rows']),
        ]);
    }

    /**
     * Stock opname documents with a variance summary of system versus counted
     * quantities.
     */
    public function stockOpnames(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $page = $this->service->stockOpnames(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(StockOpnameSummaryResource::collection($page), 'Stock opnames');
    }

    /**
     * Warehouse transfer documents with their status, item counts and quantity
     * totals.
     */
    public function warehouseTransfers(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $request->validate($this->rules());

        $page = $this->service->warehouseTransfers(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(WarehouseTransferSummaryResource::collection($page), 'Warehouse transfers');
    }

    /**
     * The filter window every inventory report accepts, plus the rules an
     * endpoint adds for its own parameters.
     *
     * The company is the resolved business context unless the request states
     * one, and a company the user cannot reach yields no rows because the
     * service scopes to the user's companies first.
     *
     * @param  array<string, array<string>>  $extra
     * @return array<string, array<string>>
     */
    private function rules(array $extra = []): array
    {
        return array_merge([
            'company_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'movement_type' => ['nullable', 'string'],
            'reference_type' => ['nullable', 'string'],
            'reference_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'from_warehouse_id' => ['nullable', 'integer'],
            'to_warehouse_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $extra);
    }

    /**
     * @return array{company_id: ?int, warehouse_id: ?int, location_id: ?int, category_id: ?int, brand_id: ?int, product_id: ?int, product_variant_id: ?int, movement_type: ?string, reference_type: ?string, reference_id: ?int, status: ?string, from_warehouse_id: ?int, to_warehouse_id: ?int, date_from: ?string, date_to: ?string, search: ?string, direction: string}
     */
    private function filters(Request $request): array
    {
        return [
            'company_id' => $request->integer('company_id') ?: $this->context->companyId(),
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'location_id' => $request->integer('location_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'brand_id' => $request->integer('brand_id') ?: null,
            'product_id' => $request->integer('product_id') ?: null,
            'product_variant_id' => $request->integer('product_variant_id') ?: null,
            'movement_type' => $request->filled('movement_type') ? (string) $request->string('movement_type') : null,
            'reference_type' => $request->filled('reference_type') ? (string) $request->string('reference_type') : null,
            'reference_id' => $request->integer('reference_id') ?: null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'from_warehouse_id' => $request->integer('from_warehouse_id') ?: null,
            'to_warehouse_id' => $request->integer('to_warehouse_id') ?: null,
            'date_from' => $request->date('date_from')?->toDateString(),
            'date_to' => $request->date('date_to')?->toDateString(),
            'search' => $request->filled('search') ? (string) $request->string('search') : null,
            'direction' => $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 20)));
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: ?int, to: ?int}
     */
    private function paginationMeta(mixed $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
