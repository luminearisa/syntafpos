<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
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
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'adjustment_type' => $this->when($this->adjustment_type, fn () => $this->adjustment_type->value),
            'reason' => $this->when($this->reason, fn () => $this->reason->value),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'requested_by_user' => new UserResource($this->whenLoaded('requestedBy')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'items' => StockAdjustmentItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
