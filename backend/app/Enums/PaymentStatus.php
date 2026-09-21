<?php

namespace App\Enums;

/**
 * The state of one tender on a sale, kept separate from the sale's own status.
 *
 * A payment can fail or be voided without the goods leaving or returning, so
 * reading "is this sale paid" from the payment rows means something different
 * from reading "has this sale been completed".
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Voided => 'Voided',
        };
    }

    /**
     * Whether the amount counts towards the balance collected. A voided tender
     * stays on the record for the audit trail but settles nothing.
     */
    public function countsTowardPaid(): bool
    {
        return $this === self::Completed;
    }
}
