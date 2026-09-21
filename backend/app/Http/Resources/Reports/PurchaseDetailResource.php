<?php

namespace App\Http\Resources\Reports;

use App\Http\Resources\BranchResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\UnitResource;
use App\Http\Resources\WarehouseResource;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purchase order line in the detail report, with the order it belongs to.
 *
 * @mixin PurchaseOrderItem
 */
class PurchaseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'unit_id' => $this->unit_id,
            'description' => $this->description,
            // Money and quantities stay strings so the client never receives a
            // rounded float.
            'quantity' => $this->quantity,
            'quantity_received' => $this->quantity_received,
            'remaining_quantity' => bcsub((string) $this->quantity, (string) $this->quantity_received, 6),
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'discount_type' => $this->when($this->discount_type, fn () => $this->discount_type->value),
            'tax_rate' => $this->tax_rate,
            'net_price' => $this->net_price,
            'tax_amount' => $this->tax_amount,
            'subtotal' => $this->subtotal,

            'purchase_order' => [
                'id' => $this->purchaseOrder->id,
                'number' => $this->purchaseOrder->number,
                'order_date' => $this->purchaseOrder->order_date?->toDateString(),
                'status' => $this->purchaseOrder->status->value,
                'grand_total' => $this->purchaseOrder->grand_total,
            ],

            'product' => new ProductResource($this->whenLoaded('product')),
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'supplier' => new SupplierResource($this->whenLoaded('purchaseOrder.supplier')),
            'warehouse' => new WarehouseResource($this->whenLoaded('purchaseOrder.warehouse')),
            'branch' => new BranchResource($this->whenLoaded('purchaseOrder.branch')),
        ];
    }
}
