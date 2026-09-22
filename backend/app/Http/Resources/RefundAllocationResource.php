<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The slice of a refund that came off one tender.
 *
 * `payment` is included when loaded so a screen can show which card or drawer the
 * money came from; the refund's own report never depends on it, because the
 * payment columns are a snapshot and the allocation only needs its own amount.
 */
class RefundAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'refund_id' => $this->refund_id,
            'sale_payment_id' => $this->sale_payment_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'payment' => $this->whenLoaded('payment', fn () => [
                'id' => $this->payment->id,
                'number' => $this->payment->number,
                'channel' => $this->payment->channel?->value,
                'channel_label' => $this->payment->channel?->label(),
                'method_name' => $this->payment->method_name,
                'amount' => (string) $this->payment->amount,
                'refunded_amount' => (string) $this->payment->refunded_amount,
                'status' => $this->payment->status?->value,
            ]),
        ];
    }
}
