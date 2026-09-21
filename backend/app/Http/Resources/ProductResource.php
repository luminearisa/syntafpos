<?php

namespace App\Http\Resources;

use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stock = $this->stockSummary();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'default_unit_id' => $this->default_unit_id,
            'tax_id' => $this->tax_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
            'product_type' => $this->product_type?->value,
            'track_inventory' => (bool) $this->track_inventory,
            'allow_negative_stock' => (bool) $this->allow_negative_stock,
            'is_sellable' => (bool) $this->is_sellable,
            'is_purchasable' => (bool) $this->is_purchasable,
            'is_active' => (bool) $this->is_active,
            'cost_price' => (string) $this->cost_price,
            'selling_price' => (string) $this->selling_price,
            'minimum_selling_price' => $this->minimum_selling_price !== null ? (string) $this->minimum_selling_price : null,
            'dimensions' => [
                'weight' => $this->weight !== null ? (string) $this->weight : null,
                'length' => $this->length !== null ? (string) $this->length : null,
                'width' => $this->width !== null ? (string) $this->width : null,
                'height' => $this->height !== null ? (string) $this->height : null,
            ],
            'minimum_stock' => (string) $this->minimum_stock,
            'maximum_stock' => $this->maximum_stock !== null ? (string) $this->maximum_stock : null,
            'reorder_point' => (string) $this->reorder_point,
            'reorder_quantity' => $this->reorder_quantity !== null ? (string) $this->reorder_quantity : null,

            'stock' => $stock,
            'costing' => $this->costing($stock['on_hand']),

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => [
                'id' => $this->defaultUnit->id,
                'name' => $this->defaultUnit->name,
                'code' => $this->defaultUnit->code,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * On hand and its derived status.
     *
     * The three statuses are mutually exclusive zones, in the order a warehouse
     * acts on them: nothing to sell first, then below the reorder trigger,
     * then healthy.
     *
     * @return array{on_hand: string, reserved: string, available: string, status: string}
     */
    private function stockSummary(): array
    {
        $onHand = bcadd('0', '0', 6);
        $reserved = bcadd('0', '0', 6);

        foreach ($this->loadedBalances() as $balance) {
            $onHand = DecimalMath::add($onHand, (string) $balance->on_hand, 6);
            $reserved = DecimalMath::add($reserved, (string) $balance->reserved, 6);
        }

        return [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => DecimalMath::sub($onHand, $reserved, 6),
            'status' => $this->stockStatus($onHand),
        ];
    }

    /**
     * Cost basis read side, straight off the ledger.
     *
     * Every figure comes from stock_balances; with no movement history the
     * answer is a real zero rather than a placeholder or a fallback estimate.
     *
     * @return array{last_purchase_cost: string, average_cost: string, current_selling_price: string, estimated_margin: string, estimated_margin_percent: string}
     */
    private function costing(string $onHand): array
    {
        $value = '0';
        $lastCost = '0';
        $lastMovementAt = null;

        foreach ($this->loadedBalances() as $balance) {
            $value = DecimalMath::add($value, DecimalMath::mul((string) $balance->on_hand, (string) $balance->average_cost), 4);

            if ($balance->last_movement_at !== null && ($lastMovementAt === null || $balance->last_movement_at->isAfter($lastMovementAt))) {
                $lastMovementAt = $balance->last_movement_at;
                $lastCost = (string) $balance->last_cost;
            }
        }

        $averageCost = bccomp($onHand, '0', 6) > 0
            ? DecimalMath::weightedAverage($onHand, $value)
            : '0';

        $sellingPrice = (string) $this->selling_price;

        // Without a cost basis there is nothing to derive a margin from, so the
        // read side reports zeros rather than treating cost as free stock.
        if (bccomp($averageCost, '0', 4) <= 0) {
            return [
                'last_purchase_cost' => $lastCost,
                'average_cost' => $averageCost,
                'current_selling_price' => $sellingPrice,
                'estimated_margin' => '0',
                'estimated_margin_percent' => '0',
            ];
        }

        return [
            'last_purchase_cost' => $lastCost,
            'average_cost' => $averageCost,
            'current_selling_price' => $sellingPrice,
            'estimated_margin' => DecimalMath::margin($sellingPrice, $averageCost),
            'estimated_margin_percent' => DecimalMath::marginPercent($sellingPrice, $averageCost),
        ];
    }

    private function stockStatus(string $onHand): string
    {
        if (bccomp($onHand, '0', 6) <= 0) {
            return 'out_of_stock';
        }

        if (bccomp($onHand, (string) $this->reorder_point, 6) <= 0) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    private function loadedBalances(): Collection
    {
        // Unloaded relations are skipped on purpose: the controllers eager load
        // the balances, and a lazy load here would fire once row per page.
        return $this->relationLoaded('stockBalances') ? $this->stockBalances : collect();
    }
}
