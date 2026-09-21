<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A point-of-sale cart in full: identity, working state and every derived
 * money figure.
 *
 * The totals reported here are the server's, recomputed on each write. The
 * till shows exactly these numbers, so what a cashier sees and what checkout
 * will charge in Subphase 3.2 cannot drift apart.
 */
class PosCartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'register_id' => $this->register_id,
            'user_id' => $this->user_id,
            'customer_id' => $this->customer_id,
            // Recall code; present only while the cart is parked.
            'number' => $this->number,
            'status' => $this->status?->value,
            'label' => $this->label,
            'held_at' => $this->held_at?->toIso8601String(),
            'currency' => $this->currency,
            'notes' => $this->notes,

            'subtotal' => (string) $this->subtotal,
            'item_discount_total' => (string) $this->item_discount_total,
            'discount_input' => (string) $this->discount_input,
            'discount_type' => $this->discount_type?->value,
            'discount_total' => (string) $this->discount_total,
            'tax_total' => (string) $this->tax_total,
            // Portion of tax_total already inside the quoted prices.
            'tax_included_total' => (string) $this->tax_included_total,
            'other_charges' => (string) $this->other_charges,
            'rounding' => (string) $this->rounding,
            'grand_total' => (string) $this->grand_total,

            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'total_quantity' => $this->whenLoaded(
                'items',
                fn () => $this->items->reduce(
                    fn (string $carry, $item) => bcadd($carry, (string) $item->quantity, 6),
                    '0'
                )
            ),

            'items' => PosCartItemResource::collection($this->whenLoaded('items')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'register' => $this->whenLoaded('register', fn () => [
                'id' => $this->register->id,
                'code' => $this->register->code,
                'name' => $this->register->name,
            ]),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
