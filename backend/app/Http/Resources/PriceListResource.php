<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'customer_group_id' => $this->customer_group_id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,

            'customer_group' => $this->whenLoaded('customerGroup', fn () => [
                'id' => $this->customerGroup->id,
                'name' => $this->customerGroup->name,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'prices_count' => $this->whenLoaded('prices', fn () => $this->prices->count()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
