<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Valuation of one product's on hand at one warehouse.
 *
 * The value is on_hand * average_cost computed by InventoryReportService with
 * DecimalMath, never by the database or by float arithmetic.
 *
 * @mixin array
 */
class StockValuationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
            'variant_sku' => $this->variant_sku,
            'variant_name' => $this->variant_name,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_code' => $this->warehouse_code,
            'warehouse_name' => $this->warehouse_name,
            'location_id' => $this->location_id,
            'unit_id' => $this->unit_id,
            'unit_code' => $this->unit_code,
            'on_hand' => bcadd((string) ($this->on_hand ?? 0), '0', 6),
            'average_cost' => bcadd((string) ($this->average_cost ?? 0), '0', 4),
            'last_cost' => bcadd((string) ($this->last_cost ?? 0), '0', 4),
            'on_hand_value' => bcadd((string) ($this->on_hand_value ?? 0), '0', 4),
        ];
    }
}
