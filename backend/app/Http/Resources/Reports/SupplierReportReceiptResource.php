<?php

namespace App\Http\Resources\Reports;

use App\Models\GoodsReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One posted goods receipt inside a supplier's received history.
 *
 * A receipt has no total column, so the value the report shows is derived from
 * its lines and attached as a transient attribute by the report service.
 *
 * @mixin GoodsReceipt
 */
class SupplierReportReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'received_total' => $this->received_total,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'number' => $this->purchaseOrder->number,
                'status' => $this->purchaseOrder->status?->value,
            ]),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
