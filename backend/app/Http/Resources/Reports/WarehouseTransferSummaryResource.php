<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One warehouse transfer document with its item count and quantity totals,
 * summed from warehouse_transfer_items in SQL.
 *
 * @mixin array
 */
class WarehouseTransferSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse_code' => $this->from_warehouse_code,
            'from_warehouse_name' => $this->from_warehouse_name,
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse_code' => $this->to_warehouse_code,
            'to_warehouse_name' => $this->to_warehouse_name,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->requested_by_name,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'items_count' => (int) ($this->items_count ?? 0),
            'total_quantity' => bcadd((string) ($this->total_quantity ?? 0), '0', 6),
            'total_received' => bcadd((string) ($this->total_received ?? 0), '0', 6),
        ];
    }
}
