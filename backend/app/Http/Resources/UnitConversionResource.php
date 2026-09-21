<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'from_unit_id' => $this->from_unit_id,
            'to_unit_id' => $this->to_unit_id,
            // bcmath-scale decimals must stay strings end to end.
            'factor' => (string) $this->factor,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'from_unit' => new UnitResource($this->whenLoaded('fromUnit')),
            'to_unit' => new UnitResource($this->whenLoaded('toUnit')),
        ];
    }
}
