<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One movement of cash through a drawer that was not a tender.
 *
 * `group` and `direction` are read off the type rather than stored, so a client can
 * colour the row and sort it into the right report line without knowing the six
 * reasons, and a seventh reason cannot arrive with a direction that disagrees with
 * its own sign.
 */
class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'register_session_id' => $this->register_session_id,
            'register_id' => $this->register_id,
            'shift_number' => $this->whenLoaded('session', fn () => $this->session?->number),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'group' => $this->type?->group(),
            'direction' => $this->type?->isInflow() ? 'in' : 'out',
            'amount' => $this->amount,
            'signed_amount' => $this->resource->signedAmount(),
            'currency' => $this->currency,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'notes' => $this->notes,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
