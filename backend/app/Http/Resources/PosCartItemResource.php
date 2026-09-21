<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cart line, as the till renders it.
 *
 * Money arrives as exact decimal strings — the same contract every other
 * monetary endpoint keeps — and the product identity is the snapshot stored on
 * the line, so a parked cart still reads correctly after a rename.
 */
class PosCartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pos_cart_id' => $this->pos_cart_id,
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

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'sku' => $this->productVariant->sku,
                'name' => $this->productVariant->name,
            ]),
        ];
    }
}
