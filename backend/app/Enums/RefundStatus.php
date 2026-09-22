<?php

namespace App\Enums;

/**
 * The life of a refund, from the counter's request to the money being gone.
 *
 * A refund is the only document in the sales family that hands money *out*, so it
 * is deliberately the most gated: it is requested against a sale, may need a
 * manager's signature before it means anything, and only settles the payment rows
 * it is allocated to when it completes.
 *
 *   Requested   raised; waiting on approval because it is at or over the shop's
 *               threshold, or waiting for whoever processes refunds
 *   Approved    someone with `refunds.approve` accepted it (or the amount was
 *               under the threshold and the shop's rule approved it on creation)
 *   Processing  handed to a gateway or a back-office queue; the money is in flight
 *   Completed   the money is back with the customer; the allocation was applied
 *   Failed      it could not be paid out; the money is still the shop's
 *   Rejected    refused before anything moved
 *
 * Completed, Failed and Rejected are terminal. A completed refund is not edited —
 * a second refund is raised, which is also what keeps the payment's
 * `refunded_amount` a running total rather than a figure someone overwrites.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * States a refund can be moved out of.
     *
     * Requested opens towards approval or rejection. Approved opens towards the
     * work of paying out, which may fail, or — for a cash refund — straight to
     * completed, because handing notes across the counter is not a queue. Failed,
     * Rejected and Completed are closed: a refund that went wrong is raised again
     * against the same payment, which is why the allocation is a running total.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected],
            self::Approved => [self::Processing, self::Completed, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Failed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether this refund's money has left the shop, and therefore whether the
     * payments it is allocated to should have been written down.
     */
    public function isSettled(): bool
    {
        return $this === self::Completed;
    }
}
