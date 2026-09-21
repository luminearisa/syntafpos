<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\ProductAnalyticsResource;
use App\Services\ProductAnalyticsService;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product analytics foundation (spec §35). A read across stock, cost, price and
 * purchasing history per product; the fast / slow / dead stock and
 * profitability classifications are deferred to a later phase and will be
 * built on the fields this endpoint exposes.
 *
 * No model is owned here, so the registered permission gate is used directly
 * instead of a policy.
 */
class ProductAnalyticsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BusinessContext $context,
        protected ProductAnalyticsService $service
    ) {}

    /**
     * Per-product analytics for the resolved company, paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', ['reports.inventory']);

        $result = $this->service->analytics(
            $request->user(),
            ['company_id' => $request->integer('company_id') ?: $this->context->companyId()],
            $this->perPage($request)
        );

        return $this->paginated(ProductAnalyticsResource::collection($result['page']), 'Product analytics');
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 20)));
    }
}
