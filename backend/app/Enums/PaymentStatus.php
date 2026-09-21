<?php

namespace App\Enums;

/**
 * The state of one tender on a sale, kept separate from the sale's own status.
 *
 * A payment can fail or be withdrawn without the goods leaving or returning, so
 * reading "is this sale paid" from the payment rows means something different
 * from reading "has this sale been completed".
 *
 * `Paid` is the only ordinary state that settles money: a Pending tender is a
 * promise the till has not been paid on, and Failed and Cancelled are how that
 * promise dies. Refunded and Partially Refunded describe a tender that settled
 * and was then given back, wholly or in part — see `countsTowardPaid()`, which
 * is what `Sale::$paid_total` is summed through.
 *
 * Subphase 3.2 named these Completed and Voided. The migration renames the
 * stored values; nothing outside the enums and the payment engine reads them.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    /**
     * Whether this tender's money is currently the shop's.
     *
     * A partially refunded payment still counts what was kept, so it counts and
     * `SalePayment::$refunded_amount` takes the given-back part off. A fully
     * refunded one counts nothing, which is why the status is not simply "paid
     * with a note": reading it as paid would settle a sale with money the
     * customer has already got back.
     */
    public function countsTowardPaid(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /**
     * States a payment can be moved out of.
     *
     * Failed and Cancelled are terminal by design: once a tender is dead it must
     * stay on the record as dead, and the cashier raises a new one. Without this a
     * stale gateway callback could resurrect a tender the drawer has already
     * written off.
     *
     * Paid opens in two directions, and they mean different things. Refunded and
     * PartiallyRefunded are money given back to a customer on a sale that stands —
     * the refund flow a later subphase owns. Cancelled is the whole sale being
     * withdrawn under it, which is how 3.2's cancellation already behaved: the
     * tender stops counting, the balance reopens, and whatever crosses the counter
     * back to the customer is the cashier's act, not this row's.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Refunded, self::PartiallyRefunded, self::Cancelled],
            self::PartiallyRefunded => [self::Refunded],
            self::Failed, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
