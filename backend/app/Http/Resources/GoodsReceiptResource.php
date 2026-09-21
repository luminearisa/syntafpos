<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
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
            'received_by' => $this->received_by,
            'number' => $this->number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            // A bare id/number pair rather than a full resource: the purchase
            // order resource belongs to the ordering module, and a receipt only
            // ever needs to point back at the document it fulfils.
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'number' => $this->purchaseOrder->number,
                'status' => $this->purchaseOrder->status?->value,
            ]),
            'received_by_user' => new UserResource($this->whenLoaded('receivedBy')),
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
