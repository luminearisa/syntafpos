<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchasing balance of one branch or warehouse, using the same shape as the
 * per-supplier balance.
 *
 * @mixin array
 */
class PurchaseDimensionSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'key' => (string) $row['key'],
            'label' => $row['label'],
            'order_count' => $row['order_count'],
            'return_count' => $row['return_count'] ?? 0,
            'total_purchased' => $row['total_purchased'],
            'total_received' => $row['total_received'],
            'total_return' => $row['total_return'],
            'net_purchased' => $row['net_purchased'],
        ];
    }
}
