<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stock opname document with its variance summary.
 *
 * System and counted totals are summed from stock_opname_items in SQL; the
 * variance is the difference column the opname recorded when it was counted.
 *
 * @mixin array
 */
class StockOpnameSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'opname_date' => $this->opname_date?->toDateString(),
            'status' => $this->when($this->status, fn () => $this->status->value),
            'warehouse_id' => $this->warehouse_id,
            'warehouse_code' => $this->warehouse_code,
            'warehouse_name' => $this->warehouse_name,
            'location_id' => $this->location_id,
            'counted_by' => $this->counted_by,
            'counted_by_name' => $this->counted_by_name,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'items_count' => (int) ($this->items_count ?? 0),
            'system_quantity' => bcadd((string) ($this->system_quantity ?? 0), '0', 6),
            'counted_quantity' => bcadd((string) ($this->counted_quantity ?? 0), '0', 6),
            'variance_quantity' => bcadd((string) ($this->variance_quantity ?? 0), '0', 6),
            'variance_value' => bcadd((string) ($this->variance_value ?? 0), '0', 4),
        ];
    }
}
