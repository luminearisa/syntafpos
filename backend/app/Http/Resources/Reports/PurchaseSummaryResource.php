<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grouping of the purchase summary report.
 *
 * The money columns are already exact decimal strings summed in SQL, so the
 * resource only shapes them.
 *
 * @mixin array
 */
class PurchaseSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'key' => (string) $group['key'],
            'label' => $group['label'],
            'order_count' => $group['order_count'],
            'total_quantity' => $group['total_quantity'],
            'total_gross' => $group['total_gross'],
            'total_discount' => $group['total_discount'],
            'total_tax' => $group['total_tax'],
            'total_grand_total' => $group['total_grand_total'],
        ];
    }
}
