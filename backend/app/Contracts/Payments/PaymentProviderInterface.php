<?php

namespace App\Contracts\Payments;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Models\SalePayment;

/**
 * Something outside the shop that has to confirm the money before a tender may
 * be called paid — the seam Subphase 3.8 fills with Midtrans.
 *
 * The engine's contract with a provider is deliberately narrow, and says nothing
 * about charge, tokenise, webhook or refund. A provider is asked one question —
 * *is this payment actually settled?* — and answers with a status plus the
 * reference its own system gave the transaction, which the engine copies onto the
 * payment row. Everything a gateway does before that question can be asked (and
 * everything it does afterwards, which is a refund and belongs to a later
 * subphase) is outside this interface, so a provider can be a QRIS dispatcher, a
 * card SDK or a file that is polled once an hour without the sale engine
 * changing.
 *
 * Two rules a provider implementation must keep, because the engine will not
 * catch their absence:
 *  - It must never mark a payment paid on its own authority. It returns a status;
 *    PaymentService is the only thing that writes one.
 *  - It must be safe to ask twice. A capture confirmation is retried after a
 *    timeout, and a provider that starts a second charge when asked about the
 *    first one has just double-billed a customer.
 */
interface PaymentProviderInterface
{
    /**
     * The key a payment method's `provider` column names to select this class.
     *
     * A short stable string — `midtrans`, `xendit` — not a class name: the
     * configured row should still resolve after the implementation is renamed or
     * swapped for a competing SDK.
     */
    public function key(): string;

    /**
     * Which of the catalogue this provider can actually take money through.
     *
     * A shop that configures QRIS and a card terminal on one Midtrans account
     * gets both from one class; a shop that configures customer credit against it
     * gets a refusal at the counter rather than a gateway error forty seconds
     * later.
     *
     * @return list<PaymentChannel>
     */
    public function supportedChannels(): array;

    /**
     * Whether this provider handles a given kind of money.
     */
    public function supports(PaymentChannel $channel): bool;

    /**
     * Set up whatever the channel needs before the customer is asked to pay —
     * a QR string to render, a hosted page to redirect to, an account number to
     * print on the invoice.
     *
     * Optional by construction: a provider that has nothing to prepare returns an
     * empty array, which is how cash-like in-person channels (a debit swipe at a
     * terminal already in the shop) can share the interface without inventing a
     * fake instruction.
     *
     * @return array<string, mixed>
     */
    public function prepare(SalePayment $payment, PaymentMethodInterface $method): array;

    /**
     * Ask the provider what it believes has happened to this payment.
     *
     * @return array{
     *     status: PaymentStatus,
     *     reference: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function verify(SalePayment $payment): array;
}
