<?php

namespace App\Enums;

/**
 * How a customer handed over money.
 *
 * Every method here is settled locally at the counter: the amounts are taken on
 * trust from the cashier and recorded as a payment row. Nothing in this enum
 * implies a provider call — card authorisation, tokens and settlement are
 * Subphase 3.8's job, and they will attach a `reference` to these rows rather
 * than replace them.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Debit = 'debit';
    case Credit = 'credit';
    case Wallet = 'wallet';
    case Transfer = 'transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Debit => 'Debit card',
            self::Credit => 'Credit card',
            self::Wallet => 'E-wallet',
            self::Transfer => 'Bank transfer',
            self::Other => 'Other',
        };
    }

    /**
     * Only cash is actually tendered, so only cash can produce change.
     *
     * A terminal or transfer either pays the requested amount or it does not;
     * handing "change" back on a card tender would be an unrecorded cash
     * movement out of the drawer.
     */
    public function takesTender(): bool
    {
        return $this === self::Cash;
    }
}
