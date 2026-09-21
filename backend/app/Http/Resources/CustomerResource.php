<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'customer_group_id' => $this->customer_group_id,
            'price_list_id' => $this->price_list_id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'type' => $this->type?->value,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'tax_number' => $this->tax_number,
            'credit_limit' => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'birthday' => $this->birthday?->toIso8601String(),
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'customer_group' => new CustomerGroupResource($this->whenLoaded('customerGroup')),
            'price_list' => new PriceListResource($this->whenLoaded('priceList')),
        ];
    }
}
