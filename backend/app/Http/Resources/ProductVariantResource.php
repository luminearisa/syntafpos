<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'cost_price' => (string) $this->cost_price,
            'selling_price' => (string) $this->selling_price,
            'weight' => $this->weight !== null ? (string) $this->weight : null,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,

            'attribute_values' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn ($value) => [
                'id' => $value->id,
                'name' => $value->name,
                'attribute_id' => $value->attribute_id,
                'attribute' => $value->relationLoaded('attribute') ? [
                    'id' => $value->attribute->id,
                    'name' => $value->attribute->name,
                ] : null,
            ])->values()->all()),

            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
