<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchasing totals of one product across the filtered orders.
 *
 * @mixin array
 */
class PurchaseProductSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'product_id' => $row['product_id'],
            'product_sku' => $row['product_sku'],
            'product_name' => $row['product_name'],
            'order_count' => $row['order_count'],
            'quantity_ordered' => $row['quantity_ordered'],
            'quantity_received' => $row['quantity_received'],
            'remaining_quantity' => $row['remaining_quantity'],
            'gross_value' => $row['gross_value'],
            'net_value' => $row['net_value'],
            'tax_amount' => $row['tax_amount'],
            'average_unit_cost' => $row['average_unit_cost'],
        ];
    }
}
