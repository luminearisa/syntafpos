<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'code' => $this->code,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'tax_number' => $this->tax_number,
            'logo' => $this->logo,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'fiscal_year_start' => $this->fiscal_year_start?->toDateString(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),

            'branches_count' => $this->whenCounted('branches'),
            'warehouses_count' => $this->whenCounted('warehouses'),
            'registers_count' => $this->whenCounted('registers'),
        ];
    }
}
