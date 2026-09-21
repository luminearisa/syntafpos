<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'number' => $this->number,
            'request_date' => $this->request_date?->toDateString(),
            'required_date' => $this->required_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'requested_by_user' => new UserResource($this->whenLoaded('requestedBy')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'items' => PurchaseRequestItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'purchase_orders' => PurchaseOrderResource::collection($this->whenLoaded('purchaseOrders')),
        ];
    }
}
