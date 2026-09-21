<?php

namespace App\Http\Resources;

use App\Enums\UnitType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type instanceof UnitType ? $this->type->value : $this->type,
            'precision' => $this->precision,
            'is_base' => (bool) $this->is_base,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
        ];
    }
}
