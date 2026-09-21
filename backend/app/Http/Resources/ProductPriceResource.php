<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_list_id' => $this->price_list_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'price_type' => $this->price_type?->value,
            'price' => (string) $this->price,
            'minimum_price' => $this->minimum_price !== null ? (string) $this->minimum_price : null,

            'price_list' => $this->whenLoaded('priceList', fn () => [
                'id' => $this->priceList->id,
                'name' => $this->priceList->name,
                'currency' => $this->priceList->currency,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'product_variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'sku' => $this->productVariant->sku,
                'name' => $this->productVariant->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
