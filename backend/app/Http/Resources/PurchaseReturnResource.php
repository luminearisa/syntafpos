<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'returned_by' => $this->returned_by,
            'number' => $this->number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'total_amount' => $this->total_amount,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            // Bare pointers rather than full resources: the linked documents
            // belong to the ordering module and a return only needs to name them.
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'number' => $this->purchaseOrder->number,
                'status' => $this->purchaseOrder->status?->value,
            ]),
            'goods_receipt' => $this->whenLoaded('goodsReceipt', fn () => [
                'id' => $this->goodsReceipt->id,
                'number' => $this->goodsReceipt->number,
                'status' => $this->goodsReceipt->status?->value,
            ]),
            'returned_by_user' => new UserResource($this->whenLoaded('returnedBy')),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
