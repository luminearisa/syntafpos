<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a sales return.
 *
 * Everything here is a snapshot taken from the original sale line and scaled to
 * the returned quantity, so the slip prints the price the customer actually paid
 * rather than today's catalogue. `line_total` carries the line's share of the
 * order-level discount, charges and rounding; `unit_cost`/`total_cost` are the
 * cost basis the goods left at, kept for the Phase 4 COGS reversal.
 */
class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_return_id' => $this->sale_return_id,
            'sale_item_id' => $this->sale_item_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            'tax_id' => $this->tax_id,

            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'variant_name' => $this->variant_name,
            'unit_code' => $this->unit_code,

            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'discount' => (string) $this->discount,
            'discount_type' => $this->discount_type?->value,
            'discount_amount' => (string) $this->discount_amount,
            'tax_rate' => (string) $this->tax_rate,
            'tax_mode' => $this->tax_mode,
            'tax_amount' => (string) $this->tax_amount,
            'line_subtotal' => (string) $this->line_subtotal,
            'line_total' => (string) $this->line_total,

            'unit_cost' => (string) $this->unit_cost,
            'total_cost' => (string) $this->total_cost,

            'restock' => (bool) $this->restock,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ];
    }
}
