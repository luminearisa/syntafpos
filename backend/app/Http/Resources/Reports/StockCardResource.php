<?php

namespace App\Http\Resources\Reports;

use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One running-ledger row of a product's stock card.
 *
 * The balance is the recorded `balance_after` from the immutable ledger, never
 * recomputed by summing the page: a paginated card stays truthful about the
 * balance at that point in time. In/out are the signed quantity split by
 * direction, and cost is the movement's total cost.
 *
 * @mixin array
 */
class StockCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quantity = bcadd((string) ($this->quantity ?? 0), '0', 6);
        $zero = bcadd('0', '0', 6);
        $incoming = bccomp($quantity, '0', 6) > 0 ? $quantity : $zero;
        $outgoing = bccomp($quantity, '0', 6) < 0 ? bcmul($quantity, '-1', 6) : $zero;

        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'movement_type' => $this->movement_type?->value,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reference_label' => self::referenceLabel($this->reference_type),
            'warehouse_id' => $this->warehouse_id,
            'warehouse_code' => $this->warehouse_code,
            'warehouse_name' => $this->warehouse_name,
            'location_id' => $this->location_id,
            'unit_id' => $this->unit_id,
            'unit_code' => $this->unit_code,
            'in' => $incoming,
            'out' => $outgoing,
            'balance' => bcadd((string) ($this->balance_after ?? 0), '0', 6),
            'unit_cost' => bcadd((string) ($this->unit_cost ?? 0), '0', 4),
            'total_cost' => bcadd((string) ($this->total_cost ?? 0), '0', 4),
            'notes' => $this->notes,
        ];
    }

    /**
     * A human label for the document that caused a movement, derived from the
     * polymorphic reference_type rather than stored, so a new document type is
     * reported as soon as it writes to the ledger. Shared with the movement
     * report, which shows the same column.
     */
    public static function referenceLabel(?string $referenceType): ?string
    {
        if (! $referenceType) {
            return null;
        }

        return self::LABELS[$referenceType] ?? class_basename($referenceType);
    }

    /**
     * @var array<int, string>
     */
    private const LABELS = [
        GoodsReceipt::class => 'Goods Receipt',
        PurchaseReturn::class => 'Purchase Return',
        StockAdjustment::class => 'Stock Adjustment',
        StockOpname::class => 'Stock Opname',
        WarehouseTransfer::class => 'Warehouse Transfer',
    ];
}
