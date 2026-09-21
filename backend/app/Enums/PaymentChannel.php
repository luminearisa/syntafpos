<?php

namespace App\Enums;

/**
 * The kinds of money movement a tender can be — the catalogue, not the config.
 *
 * Phase 3.3 splits payment in two, and this enum is the half that is not
 * configurable: a QRIS payment is a QRIS payment wherever it happens. What a
 * shop decides — its display name, whether the cashier must type a reference,
 * the notes it accepts, whether it is offered at all — lives on `PaymentMethod`
 * model rows, which point at one channel each. A tender therefore stores both:
 * the configured method it was taken on, and this channel as the immutable
 * description of what kind of money it was.
 *
 * The distinction is what keeps a gateway pluggable in 3.8. A provider is
 * selected by channel and by the method's provider key; it never has to guess
 * from a label a marketing team can rename.
 */
enum PaymentChannel: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Debit = 'debit';
    case CreditCard = 'credit_card';
    case Qris = 'qris';
    case EWallet = 'e_wallet';
    case VirtualAccount = 'virtual_account';
    case CustomerCredit = 'customer_credit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Debit => 'Debit card',
            self::CreditCard => 'Credit card',
            self::Qris => 'QRIS',
            self::EWallet => 'E-wallet',
            self::VirtualAccount => 'Virtual account',
            self::CustomerCredit => 'Customer credit',
            self::Other => 'Other',
        };
    }

    /**
     * Whether money can be handed over more than the amount due, so that change
     * comes back out of the till.
     *
     * Only cash does this. A terminal, a QRIS scan or a transfer either pays the
     * figure it was asked to pay or it does not; "change" on any of those would
     * be an unrecorded cash movement out of the drawer wearing a card payment's
     * reference. That is why this is a property of the channel rather than a
     * switch a shop can flip: the physical drawer is not configurable.
     */
    public function takesTender(): bool
    {
        return $this === self::Cash;
    }

    /**
     * Whether the tender draws down a customer's account rather than taking money
     * in: a charge to a company account, or store credit already held.
     *
     * A sale settled this way cannot be a walk-in's — there is no account to write
     * the debt against, and a cash drawer cannot reconcile a payment that never
     * crossed it. The payment engine refuses such a tender where the sale has no
     * customer, because the till is the only place anyone can still ask.
     */
    public function usesCustomerAccount(): bool
    {
        return $this === self::CustomerCredit;
    }

    /**
     * The channels a new company is seeded with, in the order a till lists them.
     *
     * Deliberately not every case: the catalogue is nine kinds of money, a shop
     * starts with the six it can take on day one and switches the rest on when it
     * has a provider or an account policy for them.
     *
     * @return list<self>
     */
    public static function defaultChannels(): array
    {
        return [
            self::Cash,
            self::Qris,
            self::Debit,
            self::CreditCard,
            self::EWallet,
            self::BankTransfer,
        ];
    }
}
