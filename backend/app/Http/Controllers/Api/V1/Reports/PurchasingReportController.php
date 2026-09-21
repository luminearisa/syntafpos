<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\OutstandingPurchaseOrderResource;
use App\Http\Resources\Reports\PurchaseDetailResource;
use App\Http\Resources\Reports\PurchaseDimensionSummaryResource;
use App\Http\Resources\Reports\PurchaseProductSummaryResource;
use App\Http\Resources\Reports\PurchaseReturnSummaryResource;
use App\Http\Resources\Reports\PurchaseSummaryResource;
use App\Http\Resources\Reports\PurchaseSupplierSummaryResource;
use App\Services\PurchasingReportService;
use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only purchasing reports (spec §33).
 *
 * Reports own no single model, so there is no policy to consult: every method
 * asks the permission gate directly for reports.purchasing. All scoping happens
 * inside the service, which never reads a row outside the user's companies.
 */
class PurchasingReportController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    private const DETAIL_SORTS = ['quantity', 'unit_price', 'net_price', 'tax_amount', 'subtotal', 'created_at'];

    public function __construct(protected PurchasingReportService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules([
            'group_by' => ['nullable', 'string', 'in:'.implode(',', $this->service->summaryDimensions())],
        ]));

        $groupBy = $request->filled('group_by') ? (string) $request->string('group_by') : null;

        return $this->success([
            'totals' => $this->service->summaryTotals($request),
            'groups' => $groupBy
                ? PurchaseSummaryResource::collection($this->service->summaryGroups($request, $groupBy))
                : [],
        ], 'Purchase summary');
    }

    public function detail(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules([
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::DETAIL_SORTS)],
        ]));

        $lines = $this->service->detailQuery($request)->paginate($request->integer('per_page', 50));

        return $this->paginated(PurchaseDetailResource::collection($lines), 'Purchase detail');
    }

    public function bySupplier(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules());

        return $this->success(
            PurchaseSupplierSummaryResource::collection($this->service->bySupplier($request)),
            'Purchase by supplier'
        );
    }

    public function byProduct(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules());

        return $this->success(
            PurchaseProductSummaryResource::collection($this->service->byProduct($request)),
            'Purchase by product'
        );
    }

    public function byBranch(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules());

        return $this->success(
            PurchaseDimensionSummaryResource::collection($this->service->byBranch($request)),
            'Purchase by branch'
        );
    }

    public function byWarehouse(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules());

        return $this->success(
            PurchaseDimensionSummaryResource::collection($this->service->byWarehouse($request)),
            'Purchase by warehouse'
        );
    }

    public function returns(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules([
            'group_by' => ['nullable', 'string', 'in:'.implode(',', $this->service->returnDimensions())],
        ]));

        $groupBy = $request->filled('group_by') ? (string) $request->string('group_by') : null;

        return $this->success([
            'totals' => $this->service->returnTotals($request),
            'groups' => $groupBy
                ? PurchaseReturnSummaryResource::collection($this->service->returnGroups($request, $groupBy))
                : [],
        ], 'Purchase returns');
    }

    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.purchasing']);

        $request->validate($this->rules());

        return $this->success(
            OutstandingPurchaseOrderResource::collection($this->service->outstanding($request)),
            'Outstanding purchase orders'
        );
    }

    /**
     * The filter window every report accepts, plus the rules an endpoint adds
     * for its own parameters.
     *
     * @param  array<string, array<string>>  $extra
     * @return array<string, array<string>>
     */
    private function rules(array $extra = []): array
    {
        return array_merge([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $extra);
    }
}
