<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund: money going back, with who decided what and when.
 *
 * The status machine and its timestamps are all here, because the questions asked
 * of a refund are always "who approved it, when, against what threshold, and did
 * it pay out". The allocations say which tenders it came off; a report can sum
 * them against the payments without reading the refund's own amount twice.
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'sale_id' => $this->sale_id,
            'sale_return_id' => $this->sale_return_id,
            'register_id' => $this->register_id,
            'register_session_id' => $this->register_session_id,

            'number' => $this->number,
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'external_reference' => $this->external_reference,

            // The rule the decision was made against, snapshotted so a later
            // change to the shop's threshold cannot rewrite it.
            'approval_threshold' => (string) $this->approval_threshold,
            'approval_required' => (bool) $this->approval_required,

            'requested_by' => $this->requested_by,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,

            'metadata' => $this->metadata,
            'notes' => $this->notes,

            'allocations' => RefundAllocationResource::collection($this->whenLoaded('allocations')),
            'sale' => $this->whenLoaded('sale', fn () => [
                'id' => $this->sale->id,
                'number' => $this->sale->number,
                'grand_total' => (string) $this->sale->grand_total,
                'currency' => $this->sale->currency,
            ]),
            // Who asked, who signed and who paid out, when the caller loaded
            // them: a screen reading a refund always wants a name, not an id.
            'requested_by_user' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy?->id,
                'name' => $this->requestedBy?->name,
            ]),
            'approved_by_user' => $this->whenLoaded('approvedBy', fn () => [
                'id' => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'processed_by_user' => $this->whenLoaded('processedBy', fn () => [
                'id' => $this->processedBy?->id,
                'name' => $this->processedBy?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
