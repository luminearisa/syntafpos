<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchasing balance of one supplier: ordered value, received value, returned
 * value and the net that remains.
 *
 * @mixin array
 */
class PurchaseSupplierSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'supplier_id' => $row['supplier_id'],
            'supplier_code' => $row['supplier_code'] ?? null,
            'supplier_name' => $row['supplier_name'],
            'order_count' => $row['order_count'],
            'return_count' => $row['return_count'] ?? 0,
            'total_purchased' => $row['total_purchased'],
            'total_received' => $row['total_received'],
            'total_return' => $row['total_return'],
            'net_purchased' => $row['net_purchased'],
        ];
    }
}
