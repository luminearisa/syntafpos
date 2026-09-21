<?php

namespace App\Http\Resources;

use App\Enums\BarcodeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductBarcodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'code' => $this->code,
            'type' => BarcodeType::tryFrom((string) $this->type)?->value,

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
