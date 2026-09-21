<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tender on a sale.
 *
 * `channel` is what kind of money this was and `method_name` is what the shop
 * called it at the time — both copied onto the row when the tender was taken, so
 * a receipt printed in 2029 still names the payment correctly after the method
 * behind it has been renamed or retired. `payment_method_id` is the nullable
 * pointer back to today's configuration, useful for grouping a report and never
 * for rendering a document.
 *
 * `reference` is the seam Subphase 3.8 fills: a gateway authorisation or
 * settlement id recorded against the same row, so a card payment becomes
 * traceable without a new table or a change to the sale.
 */
class SalePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'number' => $this->number,

            'payment_method_id' => $this->payment_method_id,
            'channel' => $this->channel?->value,
            'channel_label' => $this->channel?->label(),
            'method_name' => $this->method_name,
            'method' => $this->whenLoaded('method', fn () => $this->method
                ? ['id' => $this->method->id, 'code' => $this->method->code, 'name' => $this->method->name]
                : null),

            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'tendered' => (string) $this->tendered,
            'change' => (string) $this->change,
            'refunded_amount' => (string) $this->refunded_amount,
            // What the shop still holds from this tender: the figure the sale's
            // paid total is summed from, and the one a refund screen needs so it
            // can say how much of this payment is left to give back.
            'net_amount' => $this->netAmount(),

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'reference' => $this->reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'notes' => $this->notes,
            'received_by' => $this->received_by,
            'cashier' => $this->whenLoaded('receivedBy', fn () => [
                'id' => $this->receivedBy?->id,
                'name' => $this->receivedBy?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
