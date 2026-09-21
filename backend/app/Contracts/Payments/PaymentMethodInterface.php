<?php

namespace App\Contracts\Payments;

use App\Enums\PaymentChannel;

/**
 * What the payment engine needs to know about a way of taking money.
 *
 * The point of the interface is that the engine asks a tender *how it behaves*
 * and never inspects a name. A shop may call its QRIS button "Scan", "QRIS
 * Promo" or anything else; whether that button hands change out of the drawer,
 * demands a reference before the cashier may close the ticket, and which
 * provider captures it are separate questions, and answering them from a label
 * is how a rename in the admin panel ends up breaking the takings.
 *
 * `App\Models\PaymentMethod` — the configurable row — is the implementation that
 * ships in 3.3. A test double, or a method backed by something other than the
 * database, can satisfy the same contract without the engine noticing.
 */
interface PaymentMethodInterface
{
    /**
     * The kind of money this is, from the closed catalogue.
     *
     * Behaviour that follows physics rather than policy — is there a drawer this
     * money comes out of — is read from the channel, not from a flag.
     */
    public function channel(): PaymentChannel;

    /** What the till and the receipt print against this tender. */
    public function displayName(): string;

    /**
     * Whether the cashier must record a reference before this tender may be
     * taken as paid.
     *
     * A transfer nobody can find in the bank statement is the case this protects:
     * the flag is what lets a shop insist on the evidence at the counter, where
     * the customer is still standing there, rather than in a reconciliation three
     * days later.
     */
    public function requiresReference(): bool;

    /**
     * Which provider captures money taken on this method, or null when the
     * counter's own word is the record.
     *
     * Null is the 3.3 answer for every method: no gateway is wired. The moment a
     * shop's row names `midtrans` and no such provider is registered, the engine
     * refuses the tender rather than stamping it paid — see PaymentService.
     */
    public function providerKey(): ?string;

    /**
     * Whether more money may be handed over than is owed, so that change comes
     * back.
     *
     * Cash only, and it is a property of the channel rather than a shop's
     * setting: every other kind of either pays the asked figure or does not.
     */
    public function takesTender(): bool;
}
