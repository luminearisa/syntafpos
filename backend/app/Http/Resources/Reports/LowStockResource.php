<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product at or below its reorder point.
 *
 * Only products that track inventory ever reach this resource; the report's
 * filter is part of the query, not the presentation. available is bcmath
 * derived from the summed balance columns, and the suggested reorder quantity
 * is the product's reorder_quantity when one is set, otherwise the shortfall
 * to the reorder point.
 *
 * @mixin array
 */
class LowStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = bcsub((string) $this->on_hand, (string) $this->reserved, 6);

        return [
            'product_id' => $this->product_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_code' => $this->warehouse_code,
            'warehouse_name' => $this->warehouse_name,
            'unit_id' => $this->unit_id,
            'unit_code' => $this->unit_code,
            'on_hand' => bcadd((string) ($this->on_hand ?? 0), '0', 6),
            'reserved' => bcadd((string) ($this->reserved ?? 0), '0', 6),
            'available' => $available,
            'reorder_point' => bcadd((string) ($this->reorder_point ?? 0), '0', 6),
            'minimum_stock' => bcadd((string) ($this->minimum_stock ?? 0), '0', 6),
            'suggested_reorder_quantity' => $this->suggestedReorderQuantity($available),
        ];
    }

    private function suggestedReorderQuantity(string $available): string
    {
        $configured = bcadd((string) ($this->reorder_quantity ?? 0), '0', 6);

        if (bccomp($configured, '0', 6) > 0) {
            return $configured;
        }

        // No master-data reorder quantity: fall back to what would lift
        // available back to the reorder point, never below zero.
        $shortfall = bcsub((string) $this->reorder_point, $available, 6);

        return bccomp($shortfall, '0', 6) > 0 ? $shortfall : '0';
    }
}
