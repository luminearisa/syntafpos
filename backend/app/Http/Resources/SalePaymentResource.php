<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tender on a sale.
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
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),
            'amount' => (string) $this->amount,
            'tendered' => (string) $this->tendered,
            'change' => (string) $this->change,
            'status' => $this->status?->value,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'received_by' => $this->received_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
