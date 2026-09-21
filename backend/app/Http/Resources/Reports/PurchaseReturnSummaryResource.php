<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grouping of the purchase returns report.
 *
 * Only posted returns are summed, so a draft return never appears in a total.
 *
 * @mixin array
 */
class PurchaseReturnSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'key' => (string) $row['key'],
            'label' => $row['label'],
            'product_sku' => $row['product_sku'] ?? null,
            'return_count' => $row['return_count'],
            'total_quantity' => $row['total_quantity'],
            'total_amount' => $row['total_amount'],
        ];
    }
}
