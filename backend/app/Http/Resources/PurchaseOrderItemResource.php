<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            'tax_id' => $this->tax_id,
            'description' => $this->description,
            // Money and quantities stay strings so the client never sees a
            // rounded float.
            'quantity' => $this->quantity,
            'quantity_received' => $this->quantity_received,
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'discount_type' => $this->when($this->discount_type, fn () => $this->discount_type->value),
            // The tax rate is snapshotted at write time, so a later rate change
            // cannot rewrite this line's history.
            'tax_rate' => $this->tax_rate,
            'net_price' => $this->net_price,
            'tax_amount' => $this->tax_amount,
            'subtotal' => $this->subtotal,
            'notes' => $this->notes,

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'tax' => new TaxResource($this->whenLoaded('tax')),
        ];
    }
}
