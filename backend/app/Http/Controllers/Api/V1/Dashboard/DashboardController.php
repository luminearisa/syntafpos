<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\BusinessContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 1 dashboard: real zero states, no placeholder transactions.
 *
 * Widgets whose backing modules do not exist yet resolve to their empty
 * value (0 or empty list). Each carries an `available` flag so the UI can
 * mark it "no data yet" instead of implying a real figure.
 */
class DashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BusinessContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $company = $this->context->company();

        return $this->success([
            'context' => [
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'currency' => $company->currency,
                ] : null,
                'branch' => null,
                'warehouse' => null,
                'register' => null,
            ],
            'widgets' => [
                [
                    'key' => 'total_sales',
                    'label' => 'Total Sales',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
                [
                    'key' => 'total_transactions',
                    'label' => 'Transactions',
                    'value' => 0,
                    'available' => false,
                ],
                [
                    'key' => 'gross_profit',
                    'label' => 'Gross Profit',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
                [
                    'key' => 'expenses',
                    'label' => 'Expenses',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
                [
                    'key' => 'net_profit',
                    'label' => 'Net Profit',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
                [
                    'key' => 'low_stock',
                    'label' => 'Low Stock Items',
                    'value' => 0,
                    'available' => false,
                ],
                [
                    'key' => 'outstanding_receivable',
                    'label' => 'Outstanding Receivable',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
                [
                    'key' => 'outstanding_payable',
                    'label' => 'Outstanding Payable',
                    'value' => 0,
                    'currency' => $company?->currency ?? 'IDR',
                    'available' => false,
                ],
            ],
            'charts' => [
                'revenue_trend' => ['available' => false, 'data' => []],
                'profit_trend' => ['available' => false, 'data' => []],
                'sales_by_category' => ['available' => false, 'data' => []],
                'sales_by_payment_method' => ['available' => false, 'data' => []],
                'sales_by_outlet' => ['available' => false, 'data' => []],
            ],
            'counts' => [
                'companies' => $request->user()->companies()->count(),
                'branches' => $company?->branches()->count() ?? 0,
                'warehouses' => $company?->warehouses()->count() ?? 0,
                'registers' => $company?->registers()->count() ?? 0,
                'users' => $company ? $request->user()->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))->count() : 0,
            ],
        ]);
    }
}
