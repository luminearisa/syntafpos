<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One immutable ledger row as shown by the stock movement report.
 *
 * quantity is signed: positive into stock, negative out of stock, so the
 * direction is derivable without a second column.
 *
 * @mixin array
 */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'movement_type' => $this->movement_type?->value,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reference_label' => $this->referenceLabel(),
            'product_id' => $this->product_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
            'variant_sku' => $this->variant_sku,
            'variant_name' => $this->variant_name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_code' => $this->warehouse_code,
            'warehouse_name' => $this->warehouse_name,
            'location_id' => $this->location_id,
            'unit_id' => $this->unit_id,
            'unit_code' => $this->unit_code,
            'quantity' => bcadd((string) ($this->quantity ?? 0), '0', 6),
            'balance_after' => bcadd((string) ($this->balance_after ?? 0), '0', 6),
            'unit_cost' => bcadd((string) ($this->unit_cost ?? 0), '0', 4),
            'total_cost' => bcadd((string) ($this->total_cost ?? 0), '0', 4),
            'created_by' => $this->created_by,
            'creator_name' => $this->creator_name,
            'notes' => $this->notes,
        ];
    }

    private function referenceLabel(): ?string
    {
        return StockCardResource::referenceLabel($this->reference_type);
    }
}
