<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a sale, as an invoice or a receipt prints it.
 *
 * Everything here is the snapshot taken at checkout: the name and SKU are the
 * line's own columns, not a join to the product. That is what lets a receipt
 * from last year still read correctly after the catalogue has moved on, and why
 * the product relation is only offered for the rare screen that wants to click
 * through to the item as it is today.
 */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            'tax_id' => $this->tax_id,

            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'barcode' => $this->barcode,
            'variant_name' => $this->variant_name,
            'unit_code' => $this->unit_code,

            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'price_source' => $this->price_source,
            'discount' => (string) $this->discount,
            'discount_type' => $this->discount_type?->value,
            'discount_amount' => (string) $this->discount_amount,
            'tax_rate' => (string) $this->tax_rate,
            'tax_mode' => $this->tax_mode,
            'tax_amount' => (string) $this->tax_amount,
            'line_subtotal' => (string) $this->line_subtotal,
            'line_total' => (string) $this->line_total,
            'notes' => $this->notes,
        ];
    }
}
