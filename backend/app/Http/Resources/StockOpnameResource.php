<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOpnameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'location_id' => $this->location_id,
            'number' => $this->number,
            'opname_date' => $this->opname_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'counted_by' => $this->counted_by,
            'reviewed_by' => $this->reviewed_by,
            'approved_by' => $this->approved_by,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'counted_by_user' => new UserResource($this->whenLoaded('countedBy')),
            'reviewed_by_user' => new UserResource($this->whenLoaded('reviewedBy')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'items' => StockOpnameItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
