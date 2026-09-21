<?php

namespace App\Http\Resources\Reports;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purchase order inside a supplier's order history.
 *
 * @mixin PurchaseOrder
 */
class SupplierReportOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'grand_total' => $this->grand_total,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
