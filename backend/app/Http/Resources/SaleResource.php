<?php

namespace App\Http\Resources;

use App\Enums\SaleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sale in full, which doubles as the invoice document.
 *
 * The header carries the work order's invoice detail — company, outlet, customer,
 * invoice number, date, items, discount, tax, total and payment status — read
 * from the snapshot columns rather than from live relations, so the same payload
 * renders correctly on a receipt printed years later.
 *
 * Money is reported as exact decimal strings, and the payment state is given both
 * as figures (paid, balance) and as the label a cashier would say out loud.
 */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balance = bcsub((string) $this->grand_total, (string) $this->paid_total, 4);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'warehouse_id' => $this->warehouse_id,
            'register_id' => $this->register_id,
            // Which drawer the ticket's cash belongs to, and therefore which shift's
            // report it appears on. Null when it was billed outside any shift, which
            // the shift report then shows as cash it cannot account for.
            'register_session_id' => $this->register_session_id,
            'customer_id' => $this->customer_id,
            'cashier_id' => $this->cashier_id,
            'pos_cart_id' => $this->pos_cart_id,

            // Invoice number, from the numbering engine: INV-2026-000001.
            'number' => $this->number,
            'date' => $this->date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'stock_posted_at' => $this->stock_posted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,

            'currency' => $this->currency,
            'subtotal' => (string) $this->subtotal,
            'item_discount_total' => (string) $this->item_discount_total,
            'discount_input' => (string) $this->discount_input,
            'discount_type' => $this->discount_type?->value,
            'discount_total' => (string) $this->discount_total,
            // All tax charged, of which this portion was already inside the
            // quoted prices rather than added on top.
            'tax_total' => (string) $this->tax_total,
            'tax_included_total' => (string) $this->tax_included_total,
            'other_charges' => (string) $this->other_charges,
            'rounding' => (string) $this->rounding,
            'grand_total' => (string) $this->grand_total,

            'paid_total' => (string) $this->paid_total,
            'balance_due' => $balance,
            'change_due' => (string) $this->change_due,
            // Payment status for the invoice line: what the customer owes, said
            // plainly, derived from the figures above rather than stored twice.
            'payment_status' => $this->paymentStatus($balance),
            'fully_paid' => $this->status === SaleStatus::Completed || bccomp($balance, '0', 4) <= 0,

            // Money that has gone back to the customer, and what is left that a
            // further refund may draw on. A completed sale stays completed after a
            // refund, so `paid_total` net of the tenders' `refunded_amount` is the
            // refundable figure rather than a reopened balance.
            'refunded_total' => $this->refundedTotal(),
            'refundable_amount' => (string) $this->paid_total,
            // Goods returned, present only when the document was loaded with its
            // returns so a list does not run a query per row.
            'returned_total' => $this->whenLoaded('returns', fn () => $this->sumReturnTotals()),
            'returns' => SaleReturnResource::collection($this->whenLoaded('returns')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),

            'notes' => $this->notes,

            // Outlet and customer snapshots; present even if the records behind
            // them have been renamed or deleted since.
            'outlet' => [
                'name' => $this->branch_name,
                'address' => $this->branch_address,
                'phone' => $this->branch_phone,
            ],
            'customer' => [
                'id' => $this->customer_id,
                'name' => $this->customer_name,
                'code' => $this->customer_code,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
                'address' => $this->customer_address,
            ],

            // The list asks for a count instead of loading the lines, so the
            // column falls back to that when the relation itself is absent.
            'item_count' => $this->whenLoaded(
                'items',
                fn () => $this->items->count(),
                $this->items_count
            ),
            'total_quantity' => $this->whenLoaded(
                'items',
                fn () => $this->items->reduce(
                    fn (string $carry, $item) => bcadd($carry, (string) $item->quantity, 6),
                    '0'
                )
            ),

            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),

            'company' => new CompanyResource($this->whenLoaded('company')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'register' => $this->whenLoaded('register', fn () => [
                'id' => $this->register->id,
                'code' => $this->register->code,
                'name' => $this->register->name,
            ]),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier?->id,
                'name' => $this->cashier?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * How to describe the money state on an invoice.
     *
     * Cancelled is checked against the status rather than the balance because a
     * cancelled ticket's balance is zero for a reason a customer should not read
     * as "paid".
     */
    private function paymentStatus(string $balance): string
    {
        return match (true) {
            $this->status === SaleStatus::Cancelled => 'Cancelled',
            // A completed ticket was paid; refunds against it are a second
            // document and do not turn the invoice back into "partially paid".
            $this->status === SaleStatus::Completed => 'Paid',
            bccomp((string) $this->paid_total, '0', 4) <= 0 => 'Unpaid',
            bccomp($balance, '0', 4) > 0 => 'Partially paid',
            default => 'Paid',
        };
    }

    /**
     * The value of goods returned so far, summed off the loaded relation.
     */
    private function sumReturnTotals(): string
    {
        $total = '0';

        foreach ($this->returns as $return) {
            if ($return->status->isPosted()) {
                $total = bcadd($total, (string) $return->grand_total, 4);
            }
        }

        return $total;
    }
}
