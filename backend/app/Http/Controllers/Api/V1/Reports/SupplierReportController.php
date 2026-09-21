<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\SupplierReportDetailResource;
use App\Http\Resources\Reports\SupplierReportSummaryResource;
use App\Services\SupplierReportService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier reports (spec §34): read-only aggregations over the purchasing
 * documents. No model is owned here, so the registered permission gate is used
 * directly instead of a policy.
 */
class SupplierReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BusinessContext $context,
        protected SupplierReportService $service
    ) {}

    /**
     * Per-supplier purchase summary for the resolved company.
     *
     * Filters: date_from / date_to over the goods receipts, warehouse_id and
     * branch_id over the documents, and supplier_id to narrow the list to one
     * supplier. Paginated.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $result = $this->service->summary(
            $request->user(),
            $this->filters($request),
            $this->perPage($request)
        );

        return $this->paginated(SupplierReportSummaryResource::collection($result['page']), 'Supplier purchase summary');
    }

    /**
     * One supplier's drill-down: its summary alongside the orders, receipts and
     * returns that produced it. The three history sections share one page and
     * per_page, each paginated independently with its own meta block.
     *
     * A supplier outside the resolved company is a 404, because the report has
     * no permission to read it at all.
     */
    public function detail(Request $request, int $supplier): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $result = $this->service->detail(
            $request->user(),
            $supplier,
            $this->filters($request),
            $this->perPage($request),
            max(1, $request->integer('page', 1))
        );

        return $this->success(new SupplierReportDetailResource($result), 'Supplier purchase detail', 200, [
            'purchase_orders' => $this->paginationMeta($result['orders']),
            'goods_receipts' => $this->paginationMeta($result['receipts']),
            'purchase_returns' => $this->paginationMeta($result['returns']),
        ]);
    }

    /**
     * Report filters. The company is the resolved business context unless the
     * request states one, and a company the user cannot reach yields no rows
     * because the service scopes to the user's companies first.
     *
     * @return array{company_id: ?int, date_from: ?string, date_to: ?string, warehouse_id: ?int, branch_id: ?int, supplier_id: ?int}
     */
    private function filters(Request $request): array
    {
        return [
            'company_id' => $request->integer('company_id') ?: $this->context->companyId(),
            'date_from' => $request->date('date_from')?->toDateString(),
            'date_to' => $request->date('date_to')?->toDateString(),
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'supplier_id' => $request->integer('supplier_id') ?: null,
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
