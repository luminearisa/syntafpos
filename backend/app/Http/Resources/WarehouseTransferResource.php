<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'number' => $this->number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'status' => $this->when($this->status, fn () => $this->status->value),
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'from_warehouse' => new WarehouseResource($this->whenLoaded('fromWarehouse')),
            'to_warehouse' => new WarehouseResource($this->whenLoaded('toWarehouse')),
            'requested_by_user' => new UserResource($this->whenLoaded('requestedBy')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'items' => WarehouseTransferItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
