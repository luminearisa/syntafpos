<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseTransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_transfer_id' => $this->warehouse_transfer_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            // Partial receiving means these two diverge; both are exposed as
            // strings so a client can compute the outstanding remainder exactly.
            'quantity' => $this->quantity,
            'quantity_received' => $this->quantity_received,
            'quantity_outstanding' => $this->quantityOutstanding(),

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
