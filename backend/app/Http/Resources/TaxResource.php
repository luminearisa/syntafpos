<?php

namespace App\Http\Resources;

use App\Enums\TaxType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'rate' => (string) $this->rate,
            'type' => $this->type instanceof TaxType ? $this->type->value : $this->type,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
        ];
    }
}
