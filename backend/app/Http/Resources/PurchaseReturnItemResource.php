<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_return_id' => $this->purchase_return_id,
            'goods_receipt_item_id' => $this->goods_receipt_item_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
