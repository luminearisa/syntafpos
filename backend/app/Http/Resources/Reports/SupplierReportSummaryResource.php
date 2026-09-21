<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One supplier's purchase summary row (spec §34).
 *
 * Every figure is aggregated from purchasing rows that really exist; a supplier
 * with no activity in the requested range reports zeros and empty lists rather
 * than being hidden, so a report can never imply business that did not happen.
 *
 * @mixin array
 */
class SupplierReportSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'supplier_id' => $summary['supplier_id'],
            'supplier_code' => $summary['supplier_code'],
            'supplier_name' => $summary['supplier_name'],

            'total_purchase' => $summary['total_purchase'],
            'purchase_count' => $summary['purchase_count'],
            'outstanding' => $summary['outstanding'],
            'last_purchase_date' => $summary['last_purchase_date'],

            // Ordered by received quantity, bounded to the report's top list.
            'top_products' => collect($summary['top_products'])->map(fn (array $product) => [
                'product_id' => $product['product_id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'quantity' => $product['quantity'],
                'total' => $product['total'],
            ])->all(),

            // One total per month that holds a posted receipt. Months with no
            // receipt carry no row, so the client gaps the range itself.
            'purchase_trend' => collect($summary['purchase_trend'])->map(fn (array $point) => [
                'month' => $point['month'],
                'total' => $point['total'],
            ])->all(),
        ];
    }
}
