<?php

namespace App\Services;

use App\Contracts\Payments\PaymentMethodInterface;
use App\Contracts\Payments\PaymentProviderRegistry;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use App\Support\MoneyFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The payment engine: the only thing in the app that writes a tender or its money.
 *
 * Phase 3.2 recorded tenders inline in SaleService. That worked while a tender was
 * one line of `SalePayment::create()`, and stopped being small the moment a tender
 * had to be checked against a configured method, a provider, a duplicate guard and
 * a state machine. So the rules moved here and SaleService now *asks* for money to
 * be taken, which is also the shape 3.8 needs: adding a gateway means adding a
 * provider behind this engine, not reopening the sale flow.
 *
 * What one call to take() guarantees, in this order:
 *  1. the sale can still receive money (not cancelled, not closed);
 *  2. the method is one this shop offers a cashier today, and its provider (if it
 *     names one) is installed and speaks this channel;
 *  3. the amount is positive and does not exceed what is outstanding — the work
 *     order's rule that a tender may not overrun the balance without a rule
 *     allowing it, which is: it may not, at all;
 *  4. the tender is not a duplicate of one already recorded on this sale;
 *  5. cash may be handed over in a larger note, and only cash; the difference
 *     becomes change, which is money leaving the drawer, not revenue;
 *  6. only then is the row written, `paid_total`/`change_due` moved, and the
 *     sale's payment state recomputed.
 *
 * Steps 1-5 are refusals, and every one of them leaves no row behind. They run
 * against a locked sale row because a till is a race: two taps on a Pay button, or
 * a cashier and a supervisor settling the same ticket from two screens, must not
 * both fit inside the remaining balance. The lock is what turns "no overpayment"
 * from an intention into a property.
 *
 * A tender's final state is decided by the method, not by the caller: an
 * unconfirmed channel with no provider installed cannot be recorded as paid, so it
 * is recorded Pending — which settles nothing, keeps the balance outstanding, and
 * leaves the ticket waiting for the capture 3.8 will perform.
 */
class PaymentService
{
    /**
     * How many tenders one sale may carry.
     *
     * The work order's example is two. Ten is generous enough for a family paying
     * a dinner in notes and cards without letting a scripted client bury a
     * transaction in rows nobody can reconcile at a shift close.
     */
    private const MAX_PAYMENTS_PER_SALE = 10;

    public function __construct(
        private NumberingService $numbering,
        private PaymentProviderRegistry $providers,
        private BusinessContext $context,
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /**
     * Take one tender against a sale.
     *
     * Callers pass what the cashier entered; nothing here is trusted that can be
     * derived — the method, its name, the currency, the change and the resulting
     * payment state are all worked out from the shop's own records and the sale's
     * own outstanding balance.
     *
     * @param  array{amount: string, tendered?: string|null, reference?: string|null, notes?: string|null, metadata?: array<string, mixed>|null}  $input
     */
    public function take(Sale $sale, User $user, PaymentMethodInterface $method, array $input, bool $allowPending = false): SalePayment
    {
        return DB::transaction(function () use ($sale, $user, $method, $input, $allowPending) {
            $locked = $this->lockForPayment($sale);

            $amount = $this->positiveAmount($input['amount'] ?? null);
            $balance = $this->outstanding($locked);

            $this->guardAgainstOverpayment($locked, $amount, $balance);
            $this->guardAgainstDuplicate($locked, $method, $amount, $input);
            $this->guardCustomerAccount($locked, $method);

            $currency = $this->currencyFor($locked);
            [$tendered, $change] = $this->resolveTender($method, $amount, $input, $currency);
            $reference = $this->resolveReference($method, $input);

            $payment = SalePayment::create($this->attributes($locked, $user, $method, $amount, $currency, $tendered, $change, $reference, $input));

            $this->confirmThroughProvider($payment, $method, $allowPending);
            $this->applyToSale($locked, $payment, $user);

            $this->audit->record('payment.take', 'sale_payment', $payment->id, null, [
                'sale_number' => $locked->number,
                'number' => $payment->number,
                'method' => $payment->method_name,
                'channel' => $payment->channel->value,
                'amount' => (string) $payment->amount,
                'change' => (string) $payment->change,
                'status' => $payment->status->value,
            ], $payment->company_id, $user->id);

            return $payment->fresh(['method', 'receivedBy:id,name']);
        });
    }

    /**
     * Record a tender the way 3.2's checkout called it: by channel name.
     *
     * The till sends `{amount, tendered}` against a channel and the shop's
     * configuration decides what that means. Resolving a channel to a method here
     * — rather than requiring a payment_method_id from every client — is what keeps
     * a POS terminal working when its operator has not configured anything yet,
     * and any company that has configured methods gets the configured behaviour,
     * because the resolution picks its own rows first.
     *
     * @param  array{channel: string|PaymentChannel, amount: string, tendered?: string|null, reference?: string|null, notes?: string|null, metadata?: array<string, mixed>|null}  $input
     */
    public function takeByChannel(Sale $sale, User $user, array $input, bool $allowPending = false): SalePayment
    {
        return $this->take(
            $sale,
            $user,
            $this->resolveMethod($sale->company_id, $input),
            $input,
            $allowPending
        );
    }

    /**
     * The configured method a tender is being taken on.
     *
     * An explicit `payment_method_id` is honoured if the shop owns it and it is
     * active — a till that has loaded the method list should name the row it is
     * using, so "the shop's QRIS, which requires a reference" applies to its
     * tender. Otherwise the active method for that channel wins, and failing that
     * the channel is taken as its own default configuration. A channel nobody has
     * configured is still a channel the catalogue knows about, and refusing a
     * customer's cash over an unseeded settings table would be a shop stopped dead
     * by a configuration problem.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolveMethod(int $companyId, array $input): PaymentMethodInterface
    {
        if (! empty($input['payment_method_id'])) {
            $configured = PaymentMethod::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find((int) $input['payment_method_id']);

            if (! $configured) {
                throw ValidationException::withMessages([
                    'payments.payment_method_id' => 'That payment method is not available at this outlet.',
                ]);
            }

            return $configured;
        }

        $channel = $this->channelFrom($input['channel'] ?? null);

        return PaymentMethod::query()
            ->where('company_id', $companyId)
            ->where('channel', $channel->value)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first() ?? $this->unconfiguredMethod($channel);
    }

    /**
     * The methods a cashier may pick on this sale, in till order.
     *
     * A company that has configured nothing gets the defaults built from the
     * catalogue rather than an empty dialog: the till has to be able to take cash
     * on a shop whose owner has never opened the settings screen.
     *
     * @return list<PaymentMethodInterface>
     */
    public function availableMethods(?int $companyId = null): array
    {
        $companyId ??= $this->context->companyId();

        $configured = PaymentMethod::query()
            ->where('company_id', $companyId)
            ->available()
            ->get();

        if ($configured->isNotEmpty()) {
            return $configured->all();
        }

        return array_map(
            fn (PaymentChannel $channel) => $this->unconfiguredMethod($channel),
            PaymentChannel::defaultChannels()
        );
    }

    /**
     * What is still owed on a sale, from the money that actually settled.
     *
     * Not `grand_total - paid_total` reading the column blindly: the figure has to
     * survive a refund, so a partially refunded tender contributes what was kept.
     * `paid_total` is then recomputed from these same net amounts by recalculate(),
     * which is the only way the two can never disagree.
     */
    public function outstanding(Sale $sale): string
    {
        return DecimalMath::sub((string) $sale->grand_total, (string) $sale->paid_total);
    }

    /**
     * Recompute a sale's paid figures from its own payment rows.
     *
     * The alternative — each take() adding on top of whatever `paid_total` said —
     * drifts permanently the first time a payment is refunded, cancelled or
     * confirmed after the fact. Deriving the figures from the rows means every
     * tender is counted exactly as its status says, however it got there, and the
     * sale's payment state stops being a claim and becomes a sum.
     */
    public function recalculate(Sale $sale, ?User $user = null): Sale
    {
        // Always re-read, never loadMissing: this runs immediately after a tender
        // has been inserted, and a sale whose payment relation was loaded earlier
        // in the request would otherwise sum a set missing the payment that is the
        // whole reason for the call.
        $sale->unsetRelation('payments');
        $sale->load('payments');

        $paid = '0';
        $changeDue = '0';

        foreach ($sale->payments as $payment) {
            if ($payment->status?->countsTowardPaid()) {
                $paid = DecimalMath::add($paid, $payment->netAmount());

                // Change only exists because money came in and part of it went
                // back out, so it is counted on the same tenders that settled.
                // A Pending tender received nothing, and a cancelled or failed one
                // has its whole tender unwound — the notes handed back are part of
                // what the reversal takes out of the drawer, not a debt on the
                // document. That is why a cancelled sale prints no change line.
                $changeDue = DecimalMath::add($changeDue, (string) $payment->change);
            }
        }

        $balance = DecimalMath::sub((string) $sale->grand_total, $paid);

        // The paid figures are computed before forceFill, and the state machine is
        // handed them as arguments: reading `$sale->paid_total` inside
        // paymentStateFor() would read the figure from *before* this tender, which
        // is how a ticket stops at Partially Paid one payment short of settled.
        $sale->forceFill([
            'paid_total' => $paid,
            'change_due' => $changeDue,
            'status' => $this->paymentStateFor($sale, $paid, $balance),
        ])->save();

        if ($user !== null) {
            $this->audit->record('payment.recalculate', 'sale', $sale->id, null, [
                'paid_total' => $paid,
                'status' => $sale->status->value,
            ], $sale->company_id, $user->id);
        }

        return $sale;
    }

    /**
     * Confirm a payment the provider said has arrived.
     *
     * 3.8's webhook calls this. Pending → Paid is the only transition offered, and
     * the money figures are re-derived afterwards rather than added, so a payment
     * confirmed twice, or after a refund, still leaves the sale summed correctly.
     */
    public function confirm(SalePayment $payment, ?string $reference = null, array $metadata = []): SalePayment
    {
        return DB::transaction(function () use ($payment, $reference, $metadata) {
            $locked = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $current = $locked->status ?? PaymentStatus::Pending;

            if ($current === PaymentStatus::Paid) {
                return $locked;
            }

            if (! $current->canTransitionTo(PaymentStatus::Paid)) {
                throw ValidationException::withMessages([
                    'payment' => "Payment {$locked->number} is {$current->label()} and cannot be confirmed.",
                ]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'reference' => $reference ?? $locked->reference,
                'metadata' => $metadata === [] ? $locked->metadata : array_merge($locked->metadata ?? [], $metadata),
            ])->save();

            $this->recalculate($locked->sale()->firstOrFail());

            return $locked->fresh();
        });
    }

    /**
     * Mark a payment as failed — a declined card, a QRIS that timed out.
     *
     * The row stays. A tender that was attempted and did not arrive is the
     * difference between a customer who paid once and one who paid twice, and the
     * drawer reconciliation cannot tell those apart from an absence.
     */
    public function fail(SalePayment $payment, ?string $reason = null): SalePayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $locked = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $current = $locked->status ?? PaymentStatus::Pending;

            if (! $current->canTransitionTo(PaymentStatus::Failed)) {
                throw ValidationException::withMessages([
                    'payment' => "Payment {$locked->number} is {$current->label()} and cannot be marked failed.",
                ]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Failed,
                'metadata' => array_merge($locked->metadata ?? [], $reason ? ['failure_reason' => $reason] : []),
            ])->save();

            $this->recalculate($locked->sale()->firstOrFail());

            return $locked->fresh();
        });
    }

    /**
     * Withdraw one tender without touching the sale's stock or status.
     *
     * A Pending tender is withdrawn freely: the money has not been confirmed, so
     * taking the row back is just undoing a promise. A Paid one is different — the
     * shop is holding that cash, and a Cancelled row on a sale that still stands
     * would leave money in the drawer with nothing accounting for it. So a settled
     * tender is only withdrawn along with its sale, which is the case
     * SaleService::cancel() passes `$withSale` for; getting paid money back to a
     * customer on a live sale is the refund flow this subphase excludes.
     *
     * What no caller does here is hand cash across the counter: the row becomes
     * Cancelled, the balance it had settled opens up again, and the physical money
     * is someone else's decision.
     */
    public function cancel(SalePayment $payment, ?User $user = null, ?string $reason = null, bool $withSale = false): SalePayment
    {
        return DB::transaction(function () use ($payment, $user, $reason, $withSale) {
            $locked = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $current = $locked->status ?? PaymentStatus::Pending;

            if ($current->countsTowardPaid() && ! $withSale) {
                throw ValidationException::withMessages([
                    'payment' => sprintf(
                        'Payment %s has settled money the shop is holding. Cancel the sale to withdraw it, or refund it — cancelling the payment alone would leave the drawer holding cash with nothing accounting for it.',
                        $locked->number
                    ),
                ]);
            }

            if (! $current->canTransitionTo(PaymentStatus::Cancelled)) {
                throw ValidationException::withMessages([
                    'payment' => "Payment {$locked->number} is {$current->label()} and cannot be cancelled.",
                ]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Cancelled,
                'metadata' => array_merge($locked->metadata ?? [], $reason ? ['cancellation_reason' => $reason] : []),
            ])->save();

            $this->recalculate($locked->sale()->firstOrFail(), $user);

            if ($user !== null) {
                $this->audit->record('payment.cancel', 'sale_payment', $locked->id, [
                    'status' => $current->value,
                ], [
                    'status' => PaymentStatus::Cancelled->value,
                    'reason' => $reason,
                ], $locked->company_id, $user->id);
            }

            return $locked->fresh();
        });
    }

    /**
     * Give part or all of a settled tender back, without touching the sale's
     * stock or status.
     *
     * This is the write that makes a refund real. The row it belongs to is the
     * record of *why*; this method is the record of *what*, and it is the only
     * place `refunded_amount` on a payment moves. Writing the figure down here
     * rather than in the refund service keeps the single-writer rule that has held
     * since 3.3: nothing else may make a tender worth less than it was.
     *
     * A tender can only be written down while it is money the shop holds — Paid
     * or Partially Refunded. A Pending, Failed, Cancelled or already fully
     * Refunded tender has nothing to give back, and refusing here is what stops a
     * refund from being applied twice after a retry.
     */
    public function refund(SalePayment $payment, string $amount, User $user, ?string $reason = null, ?int $refundId = null): SalePayment
    {
        $amount = DecimalMath::add(trim($amount), '0');

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'refund.amount' => 'A refund must be more than zero.',
            ]);
        }

        return DB::transaction(function () use ($payment, $amount, $user, $reason, $refundId) {
            $locked = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $current = $locked->status ?? PaymentStatus::Pending;

            if (! $current->countsTowardPaid()) {
                throw ValidationException::withMessages([
                    'refund.amount' => sprintf(
                        'Payment %s is %s and has no settled money left to refund.',
                        $locked->number,
                        $current->label()
                    ),
                ]);
            }

            $refundable = $locked->netAmount();

            if (bccomp($amount, $refundable, 4) > 0) {
                throw ValidationException::withMessages([
                    'refund.amount' => sprintf(
                        'That refund is larger than the %s still refundable on payment %s.',
                        MoneyFormat::format($refundable, $this->currencyFor($locked->sale()->firstOrFail())),
                        $locked->number
                    ),
                ]);
            }

            $refunded = DecimalMath::add((string) $locked->refunded_amount, $amount);
            $previousRefunded = (string) $locked->refunded_amount;
            $target = bccomp($refunded, (string) $locked->amount, 4) >= 0
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            if ($target !== $current && ! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'refund.amount' => sprintf(
                        'A payment cannot go from %s to %s.',
                        $current->label(),
                        $target->label()
                    ),
                ]);
            }

            $metadata = $locked->metadata ?? [];

            if ($refundId !== null) {
                $metadata['refunds'] = array_values(array_unique(
                    array_merge($metadata['refunds'] ?? [], [$refundId])
                ));
            }

            if ($reason !== null && $reason !== '') {
                $metadata['refund_reason'] = $reason;
            }

            $locked->forceFill([
                'refunded_amount' => $refunded,
                'status' => $target,
                'metadata' => $metadata === [] ? null : $metadata,
            ])->save();

            $sale = $this->recalculate($locked->sale()->firstOrFail(), $user);

            $this->audit->record('payment.refund', 'sale_payment', $locked->id, [
                'refunded_amount' => $previousRefunded,
                'status' => $current->value,
            ], [
                'refunded_amount' => $refunded,
                'status' => $target->value,
                'amount' => $amount,
                'reason' => $reason,
            ], $locked->company_id, $user->id);

            return $locked->fresh();
        });
    }

    /**
     * Lock the sale and prove it can still receive money.
     *
     * A Completed sale is refused because closing a ticket is the statement that
     * its money is in; a ticket with 40.000 outstanding that has been marked
     * completed is an audit finding, not a payment opportunity. A stockless draft
     * is accepted — that is what /sales/{id}/complete settles.
     */
    private function lockForPayment(Sale $sale): Sale
    {
        $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === SaleStatus::Cancelled) {
            throw ValidationException::withMessages([
                'payments' => "Sale {$locked->number} is cancelled; no payment can be taken against it.",
            ]);
        }

        if ($locked->status === SaleStatus::Completed) {
            throw ValidationException::withMessages([
                'payments' => "Sale {$locked->number} is already completed with nothing outstanding.",
            ]);
        }

        $active = $locked->payments()->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Paid->value,
            PaymentStatus::PartiallyRefunded->value,
        ])->count();

        if ($active >= self::MAX_PAYMENTS_PER_SALE) {
            throw ValidationException::withMessages([
                'payments' => 'This sale has reached the limit of '.self::MAX_PAYMENTS_PER_SALE.' payments. Cancel one before taking another.',
            ]);
        }

        // The locked row is the one every figure below is derived from; the
        // caller's copy is stale the moment another request has paid anything.
        return $locked;
    }

    /**
     * A tender must be a positive, well-formed amount.
     *
     * Zero and negatives are both refused as data errors rather than treated as
     * instructions: a payment of 0 would let a ticket look paid while settling
     * nothing, and a negative one would be a refund arriving through the front
     * door, which is not a thing the ledger can represent.
     */
    private function positiveAmount(mixed $raw): string
    {
        $amount = trim((string) ($raw ?? ''));

        if ($amount === '') {
            throw ValidationException::withMessages(['payments.amount' => 'Every payment needs an amount.']);
        }

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
            throw ValidationException::withMessages([
                'payments.amount' => 'Payment amounts must be a number with at most four decimals.',
            ]);
        }

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'payments.amount' => 'A payment must be more than zero.',
            ]);
        }

        return DecimalMath::add($amount, '0');
    }

    /**
     * "Tidak boleh payment melebihi outstanding."
     *
     * The rule is absolute, which is the point of naming it in the work order:
     * over-collecting on an invoice is not a payment, it is a deposit, a loan, or a
     * mistake — three things a POS till must not be able to produce by tapping a
     * button twice. The figure the cashier is refused is quoted back as the balance
     * it was compared against, formatted as the receipt would print it, so nobody
     * has to translate a ledger string to argue with the till.
     */
    private function guardAgainstOverpayment(Sale $sale, string $amount, string $balance): void
    {
        if (bccomp($amount, $balance, 4) <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'payments.amount' => bccomp($balance, '0', 4) <= 0
                ? sprintf(
                    'Sale %s has nothing outstanding, so it cannot take a payment.',
                    $sale->number
                )
                : sprintf(
                    'That payment is larger than the remaining balance of %s.',
                    MoneyFormat::format($balance, $this->currencyFor($sale))
                ),
        ]);
    }

    /**
     * "Tidak boleh duplicate payment."
     *
     * Two readings of the same identical tender on the same sale is a double tap
     * more often than not, and a till cannot tell the difference from the row it is
     * asked to insert — same sale, same method, same figure. So an exact repeat is
     * refused outright, and the message says what to do instead: settle part of it
     * on one tender and part on the next, or cancel the first.
     *
     * A matching `reference` is refused even when the amount differs, because a
     * bank will not thank a shop for filing one transfer slip against two sales.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardAgainstDuplicate(Sale $sale, PaymentMethodInterface $method, string $amount, array $input): void
    {
        $reference = isset($input['reference']) ? trim((string) $input['reference']) : '';

        if ($reference !== '') {
            $clash = $sale->payments()->where('reference', $reference)->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'payments.reference' => "A payment with reference {$reference} is already recorded on this sale.",
                ]);
            }
        }

        $twin = $sale->payments()
            ->where('channel', $method->channel()->value)
            ->where('amount', $amount)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Paid->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->exists();

        if (! $twin) {
            return;
        }

        throw ValidationException::withMessages([
            'payments' => sprintf(
                'This sale already has an identical payment: %s via %s. Record a different amount, or cancel the first one.',
                MoneyFormat::format($amount, $this->currencyFor($sale)),
                $method->displayName()
            ),
        ]);
    }

    /**
     * A tender drawn on a customer's account needs a customer to draw on.
     *
     * "Charge it to me" on a walk-in ticket is not a payment, it is a decision
     * someone with authority should have made before the goods were bagged — and
     * a shop can only reconcile a credit sale against a name. The till is the last
     * moment the customer is standing there to be identified, so the refusal
     * belongs here rather than in a report three weeks later.
     */
    private function guardCustomerAccount(Sale $sale, PaymentMethodInterface $method): void
    {
        if (! $method->channel()->usesCustomerAccount() || $sale->customer_id !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'payments.payment_method_id' => sprintf(
                '%s needs a customer on the sale — there is no account to charge.',
                $method->displayName()
            ),
        ]);
    }

    /**
     * Split what was handed over from what is owed, and produce the change.
     *
     * Only cash may be tendered above its amount, and only cash may produce change.
     * A card row carrying a bigger `tendered` is not a customer being generous, it
     * is a drawer that will be short at the end of the shift and a receipt claiming
     * money came back that never did — so the extra is dropped rather than
     * converted into change, and the tender is recorded at the amount paid.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string} tendered and change
     */
    private function resolveTender(PaymentMethodInterface $method, string $amount, array $input, string $currency): array
    {
        $raw = trim((string) ($input['tendered'] ?? ''));

        if ($raw !== '' && ! preg_match('/^-?\d+(\.\d{1,4})?$/', $raw)) {
            throw ValidationException::withMessages([
                'payments.tendered' => 'Cash handed over must be a number with at most four decimals.',
            ]);
        }

        if (! $method->takesTender()) {
            return [$amount, '0.0000'];
        }

        $tendered = $raw === '' ? $amount : DecimalMath::add($raw, '0');

        if (bccomp($tendered, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'payments.tendered' => 'Cash handed over must be more than zero.',
            ]);
        }

        if (bccomp($tendered, $amount, 4) < 0) {
            throw ValidationException::withMessages([
                'payments.tendered' => sprintf(
                    'Only %s was handed over, which is less than the %s being paid.',
                    MoneyFormat::format($tendered, $currency),
                    MoneyFormat::format($amount, $currency)
                ),
            ]);
        }

        return [$tendered, DecimalMath::sub($tendered, $amount)];
    }

    /**
     * A reference, if this method insists on one.
     *
     * `requires_reference` is a shop's rule, and the till is the cheapest place to
     * enforce it: the customer is still standing there with the transfer slip.
     * Three days later the reconciliation is a phone call and a lost sale.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolveReference(PaymentMethodInterface $method, array $input): ?string
    {
        $reference = trim((string) ($input['reference'] ?? ''));

        if ($reference === '') {
            if ($method->requiresReference()) {
                throw ValidationException::withMessages([
                    'payments.reference' => sprintf(
                        '%s needs a reference before it can be recorded — the bank or terminal slip number.',
                        $method->displayName()
                    ),
                ]);
            }

            return null;
        }

        if (strlen($reference) > 128) {
            throw ValidationException::withMessages([
                'payments.reference' => 'A payment reference is at most 128 characters.',
            ]);
        }

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(
        Sale $sale,
        User $user,
        PaymentMethodInterface $method,
        string $amount,
        string $currency,
        string $tendered,
        string $change,
        ?string $reference,
        array $input,
    ): array {
        $notes = trim((string) ($input['notes'] ?? ''));
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : null;

        // Which method this was, and which shop's configuration said so. The
        // configured row is snapshotted rather than joined at read time; see
        // SalePayment's header for why a receipt cannot depend on master data.
        return [
            'sale_id' => $sale->id,
            'company_id' => $sale->company_id,
            'register_id' => $sale->register_id,
            // The tender belongs to whichever shift the ticket was raised on, not
            // to whatever the register happens to be working now: paying a Pending
            // Payment sale down after a close must not move yesterday's cash into
            // today's drawer. Null on a sale raised outside a till.
            'register_session_id' => $sale->register_session_id,
            'received_by' => $user->id,
            'payment_method_id' => $method instanceof PaymentMethod ? $method->id : null,
            'number' => $this->numbering->next('payment', $sale->company_id),
            'channel' => $method->channel()->value,
            'method_name' => $method->displayName(),
            'amount' => $amount,
            'currency' => $currency,
            'tendered' => $tendered,
            'change' => $change,
            'reference' => $reference,
            'notes' => $notes === '' ? null : $notes,
            'metadata' => $metadata === [] ? null : $metadata,
            'status' => PaymentStatus::Pending->value,
        ];
    }

    /**
     * Decide whether this tender has actually settled, and record that.
     *
     * Three cases, in the order the risk runs:
     *  - The method names a provider that is installed: the provider is asked, and
     *    its answer decides the state. The till never decides.
     *  - The method names a provider that is *not* installed: refused. Recording
     *    the tender as paid would be the shop telling itself it holds money nobody
     *    has confirmed, which is the one thing a payment engine must not do.
     *  - No provider at all: the counter's word is the record. This is what a cash
     *    drawer is, and what 3.2's till was for every tender, so it stays the
     *    default for channels with nothing wired.
     */
    private function confirmThroughProvider(SalePayment $payment, PaymentMethodInterface $method, bool $allowPending): void
    {
        $key = $method->providerKey();

        if ($key !== null && ! $this->providers->has($key)) {
            throw ValidationException::withMessages([
                'payments.payment_method_id' => sprintf(
                    '%s is configured to capture through %s, which is not available on this installation.',
                    $method->displayName(),
                    $key
                ),
            ]);
        }

        if ($key !== null) {
            $provider = $this->providers->get($key);

            if (! $provider->supports($payment->channel)) {
                throw ValidationException::withMessages([
                    'payments.payment_method_id' => sprintf(
                        '%s cannot take %s payments.',
                        $key,
                        strtolower($payment->channel->label())
                    ),
                ]);
            }

            $verdict = $provider->verify($payment);
            $this->settle($payment, $verdict['status'], $verdict['reference'] ?? null, $verdict['raw'] ?? []);

            return;
        }

        if ($allowPending) {
            // A caller that asked to hold the ticket — a QRIS printed and waiting
            // for a scan — keeps the Pending state and settles nothing.
            return;
        }

        $this->settle($payment, PaymentStatus::Paid, $payment->reference);
    }

    /**
     * Move a payment to a state, with the money effects that follow.
     *
     * `paid_at` is written only on the way into Paid, and never over an existing
     * value: when the money arrived is a fact about the world, not about the row,
     * and a confirmation retried after a timeout must not move it forward.
     */
    private function settle(SalePayment $payment, PaymentStatus $status, ?string $reference = null, array $metadata = []): void
    {
        $current = $payment->status ?? PaymentStatus::Pending;

        if ($current === $status) {
            return;
        }

        if (! $current->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'payments' => sprintf(
                    'A payment cannot go from %s to %s.',
                    $current->label(),
                    $status->label()
                ),
            ]);
        }

        $payment->forceFill([
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? ($payment->paid_at ?? now()) : $payment->paid_at,
            'reference' => $reference ?? $payment->reference,
            'metadata' => $metadata === [] ? $payment->metadata : array_merge($payment->metadata ?? [], $metadata),
        ])->save();
    }

    /**
     * Fold a new tender into the sale's money and payment state.
     */
    private function applyToSale(Sale $sale, SalePayment $payment, User $user): void
    {
        $this->recalculate($sale, $user);
    }

    /**
     * The payment state a sale is in, given what it has settled and what is owed.
     *
     * Stock is not touched here — SaleService owns that and posts it when the
     * ticket is settled, so a payment engine never decides twice when goods leave
     * a shop.
     *
     * A ticket with nothing settled keeps the status it already had: the line
     * between Draft (raised with no tender at all) and Pending Payment (a customer
     * who went to the ATM) is drawn by the sale engine when the ticket is raised,
     * and recomputing money is not information about which of those two happened.
     */
    private function paymentStateFor(Sale $sale, string $paid, string $balance): SaleStatus
    {
        if ($sale->status === SaleStatus::Cancelled) {
            return SaleStatus::Cancelled;
        }

        // A completed ticket stays completed when money is refunded off it. The
        // goods left and the sale settled; a refund is a second document against
        // that fact, not a reason to reopen the ticket and re-post its stock. The
        // money it gave back is read from `refunded_amount` on the tenders and
        // from the refund rows, and the receipt still says the sale was paid.
        if ($sale->status === SaleStatus::Completed) {
            return SaleStatus::Completed;
        }

        if (bccomp($balance, '0', 4) > 0) {
            if (bccomp($paid, '0', 4) <= 0) {
                return in_array($sale->status, [SaleStatus::Draft, SaleStatus::PendingPayment], true)
                    ? $sale->status
                    : SaleStatus::PendingPayment;
            }

            return SaleStatus::PartiallyPaid;
        }

        // Settled in money terms. A zero-value ticket that never took a tender is
        // not "paid" — it is the sale engine's Draft, and saying otherwise would
        // print PAID on a document no money moved against.
        return bccomp($paid, '0', 4) > 0 ? SaleStatus::Paid : $sale->status;
    }

    private function channelFrom(mixed $raw): PaymentChannel
    {
        if ($raw instanceof PaymentChannel) {
            return $raw;
        }

        try {
            return PaymentChannel::from(trim((string) $raw));
        } catch (\ValueError) {
            throw ValidationException::withMessages([
                'payments.channel' => 'Unknown payment channel '.trim((string) $raw).'.',
            ]);
        }
    }

    private function currencyFor(Sale $sale): string
    {
        return (string) ($sale->currency ?: $this->settings->get('company.currency', 'IDR', $sale->company_id));
    }

    /**
     * A channel taken as-is, with nothing configured on top of it.
     *
     * The answer to "this shop has never opened the payment methods screen": the
     * till still gets a real method list, and each entry behaves exactly as its
     * channel does. It is not a row in the database and nothing may store a
     * payment_method_id pointing at it — hence the null in attributes().
     */
    private function unconfiguredMethod(PaymentChannel $channel): PaymentMethodInterface
    {
        return new class($channel) implements PaymentMethodInterface
        {
            public function __construct(private readonly PaymentChannel $channel) {}

            public function channel(): PaymentChannel
            {
                return $this->channel;
            }

            public function displayName(): string
            {
                return $this->channel->label();
            }

            public function requiresReference(): bool
            {
                return false;
            }

            public function providerKey(): ?string
            {
                return null;
            }

            public function takesTender(): bool
            {
                return $this->channel->takesTender();
            }
        };
    }
}
