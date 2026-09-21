<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'purchase_request_id' => $this->purchase_request_id,
            'approved_by' => $this->approved_by,
            'number' => $this->number,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'payment_terms' => $this->payment_terms,
            // The currency is snapshotted on the header at write time.
            'currency' => $this->currency,
            'status' => $this->when($this->status, fn () => $this->status->value),
            // Money: every figure is server-derived, never client-supplied.
            'subtotal' => $this->subtotal,
            'item_discount_total' => $this->item_discount_total,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'shipping_cost' => $this->shipping_cost,
            'other_charges' => $this->other_charges,
            'grand_total' => $this->grand_total,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'purchase_request' => new PurchaseRequestResource($this->whenLoaded('purchaseRequest')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
