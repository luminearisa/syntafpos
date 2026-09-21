<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOpnameItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_opname_id' => $this->stock_opname_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            // Quantities are snapshot values read straight from the column;
            // they stay strings so the client never sees a rounded float.
            'system_quantity' => $this->system_quantity,
            'counted_quantity' => $this->counted_quantity,
            'difference' => $this->difference,
            'unit_cost' => $this->unit_cost,
            'notes' => $this->notes,

            'product' => new ProductResource($this->whenLoaded('product')),
            'product_variant' => new ProductVariantResource($this->whenLoaded('productVariant')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
