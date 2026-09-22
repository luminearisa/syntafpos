<?php

namespace App\Enums;

/**
 * How a refund physically gets back to the customer.
 *
 * This is kept apart from the *payment* it is allocated against on purpose. A
 * refund of a card tender may still be handed back as cash (the terminal is down,
 * the customer wants notes), and a refund of a cash tender may be sent by bank
 * transfer because the customer has already left. What the money was, and how it
 * goes back, are two facts, and a receipt that conflates them cannot be replayed
 * against a bank statement.
 *
 * `Gateway` is the seam Subphase 3.8 fills: a provider-backed reversal that the
 * payment engine's own provider will execute. Until then it is recorded like any
 * other refund and the external reference carries the provider's id.
 */
enum RefundMethod: string
{
    case Cash = 'cash';
    case OriginalPayment = 'original_payment';
    case Manual = 'manual';
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::OriginalPayment => 'Original payment method',
            self::Manual => 'Manual',
            self::Gateway => 'Gateway',
        };
    }

    /**
     * Whether this refund is expected to be paid out by a provider rather than a
     * person. A gateway refund stays Approved until the provider confirms it; the
     * others can be completed by the cashier in one step.
     */
    public function requiresProvider(): bool
    {
        return $this === self::Gateway;
    }

    /**
     * The methods a person may pick at the counter today. Gateway is deliberately
     * absent: it is only reachable once the method's provider is installed, which
     * is a server decision rather than a cashier's.
     *
     * @return list<self>
     */
    public static function counterMethods(): array
    {
        return [self::Cash, self::OriginalPayment, self::Manual];
    }
}
