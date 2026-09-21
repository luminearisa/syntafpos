<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purchase order line that still has quantity to receive.
 *
 * Rows are grouped by order and product. The values are valued at the line
 * unit prices; the order's grand_total carries the header discount, tax and
 * shipping so no header amount is spread across lines.
 *
 * @mixin array
 */
class OutstandingPurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'purchase_order_id' => $row['purchase_order_id'],
            'purchase_order_number' => $row['purchase_order_number'],
            'order_date' => $row['order_date'],
            'status' => $row['status'],
            'grand_total' => $row['grand_total'],
            'supplier_id' => $row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'warehouse_id' => $row['warehouse_id'],
            'warehouse_name' => $row['warehouse_name'],
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'quantity_ordered' => $row['quantity_ordered'],
            'quantity_received' => $row['quantity_received'],
            'remaining_quantity' => $row['remaining_quantity'],
            'ordered_value' => $row['ordered_value'],
            'received_value' => $row['received_value'],
            'remaining_value' => $row['remaining_value'],
        ];
    }
}
