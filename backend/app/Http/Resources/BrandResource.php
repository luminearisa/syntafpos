<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'logo' => $this->logo,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
