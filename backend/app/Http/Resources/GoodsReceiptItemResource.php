<?php

namespace App\Http\Resources;

use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            // Money and quantity stay strings: the client must never round a
            // ledger figure back into the API.
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->quantity_received,
            'unit_price' => $this->unit_price,
            'unit_cost' => $this->unit_cost,
            'line_total' => DecimalMath::mul((string) $this->quantity_received, (string) $this->unit_cost),
            'notes' => $this->notes,

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
