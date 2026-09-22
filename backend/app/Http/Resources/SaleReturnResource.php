<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sales return: the slip that records goods coming back.
 *
 * Every figure is the return's own — derived from the sale's frozen lines when it
 * was written — so the document reprints correctly without reading the catalogue
 * or the sale's current state. `cost_total` is the Phase 4 seam: it is the COGS
 * reversal that the same return will post to the ledger.
 */
class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'sale_id' => $this->sale_id,
            'register_id' => $this->register_id,
            'register_session_id' => $this->register_session_id,
            'customer_id' => $this->customer_id,
            'returned_by' => $this->returned_by,

            'number' => $this->number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reason' => $this->reason,

            'currency' => $this->currency,
            'subtotal' => (string) $this->subtotal,
            'discount_total' => (string) $this->discount_total,
            'tax_total' => (string) $this->tax_total,
            'grand_total' => (string) $this->grand_total,
            'cost_total' => (string) $this->cost_total,

            'notes' => $this->notes,

            // The list asks for a count instead of loading the lines, so the
            // column falls back to that when the relation itself is absent.
            'item_count' => $this->whenLoaded(
                'items',
                fn () => $this->items->count(),
                $this->items_count
            ),

            'items' => SaleReturnItemResource::collection($this->whenLoaded('items')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'sale' => $this->whenLoaded('sale', fn () => [
                'id' => $this->sale->id,
                'number' => $this->sale->number,
                'grand_total' => (string) $this->sale->grand_total,
                'currency' => $this->sale->currency,
            ]),
            'returned_by_user' => $this->whenLoaded('returnedBy', fn () => [
                'id' => $this->returnedBy?->id,
                'name' => $this->returnedBy?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
