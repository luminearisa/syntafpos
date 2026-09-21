<?php

namespace App\Enums;

/**
 * Where a sale has got to.
 *
 * The path the work order names is Cart → Sales Order → Sales Transaction →
 * Payment → Completed, and these are the states along it: a sale raised without
 * a tender sits in Draft, one that has taken money is Partially Paid or Paid,
 * and only a settled ticket is Completed. Cancelled is the way out of any state
 * before Completed.
 *
 * Stock follows the money rather than the ringing-up: a sale's goods leave the
 * counter when the transaction is posted, which is the first time it reaches a
 * state that carries them. `Sale::$stock_posted_at` records that it has
 * happened, so a later transition never moves the same quantity twice.
 */
enum SaleStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingPayment => 'Pending payment',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * A completed sale is a closed record: no payment, edit or status change
     * applies to it any more, only the cancellation path.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
