<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product's stock position at one warehouse, aggregated from
 * stock_balances rows by InventoryReportService::stockSummary().
 *
 * On hand, reserved and available are summed in SQL; available is derived
 * with bcmath so the reported figure never drifts from the two sums.
 *
 * @mixin array
 */
class StockSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $onHand = bcadd((string) ($this->on_hand ?? 0), '0', 6);

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
            'on_hand' => $onHand,
            'reserved' => $this->quantity($this->reserved),
            'available' => bcsub($onHand, (string) ($this->reserved ?? 0), 6),
            'incoming' => $this->quantity($this->incoming),
            'outgoing' => $this->quantity($this->outgoing),
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
        ];
    }

    /**
     * Quantities are emitted as exact 6-digit decimal strings, matching the
     * ledger column scale, so figures never degrade through float rounding.
     * Available is on hand less reserved.
     */
    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}
