<?php

namespace App\Http\Resources\Reports;

use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One posted purchase return inside a supplier's return history.
 *
 * @mixin PurchaseReturn
 */
class SupplierReportReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'total_amount' => $this->total_amount,
            'reason' => $this->reason,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
