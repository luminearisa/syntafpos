<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shift, with the figures the drawer is currently at.
 *
 * The stored columns (opening, actual, variance, threshold, approval) come off the
 * row. The live figures — expected cash, the running variance, the breakdown the
 * closing report shows — are computed by `RegisterSessionService` and handed in with
 * `withSummary()`, rather than read from a column. That is what lets a till screen
 * show an expected-cash figure that cannot drift from what the close will say: both
 * are the same method over the same records.
 *
 * It is optional for a reason. A list of fifty shifts does not need five hundred
 * aggregate queries, and pretending otherwise would make the endpoint quietly
 * quadratic; those rows carry the stored figures and say `summary: null`.
 */
class RegisterSessionResource extends JsonResource
{
    /**
     * @var array<string, string|null>|null
     */
    private ?array $summary = null;

    /**
     * @param  array<string, string|null>  $summary
     */
    public function withSummary(array $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'register_id' => $this->register_id,
            'warehouse_id' => $this->warehouse_id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),

            'cashier_id' => $this->cashier_id,
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier?->id,
                'name' => $this->cashier?->name,
            ]),
            'register_code' => $this->whenLoaded('register', fn () => $this->register?->code),
            'register_name' => $this->whenLoaded('register', fn () => $this->register?->name),
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),

            'opening_balance' => $this->opening_balance,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy?->name),

            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy?->name),
            'closing_balance' => $this->closing_balance,
            'actual_balance' => $this->actual_balance,
            'variance' => $this->variance,
            'variance_threshold' => $this->variance_threshold,

            'requires_approval' => (bool) $this->requires_approval,
            'is_approved' => (bool) $this->is_approved,
            'awaiting_approval' => $this->resource->isAwaitingApproval(),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approval_note' => $this->approval_note,

            'reopen_count' => (int) $this->reopen_count,
            'reopen_reason' => $this->reopen_reason,
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'reopened_by' => $this->whenLoaded('reopenedBy', fn () => $this->reopenedBy?->name),

            'notes' => $this->notes,
            'duration_minutes' => $this->resource->durationMinutes(),

            'summary' => $this->summary,
            // Flattened for the till header and the shift row, taken from the same
            // computed array so they cannot read differently from it.
            'expected_cash' => $this->summary['expected_cash'] ?? null,
            'unattributed_cash' => $this->summary['unattributed_cash'] ?? null,
            'unattributed_count' => $this->summary['unattributed_count'] ?? null,
            'movement_count' => $this->summary['movement_count'] ?? null,
            'tender_count' => $this->summary['tender_count'] ?? null,
            'sales_count' => $this->summary['sales_count'] ?? null,

            'movements' => CashMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
