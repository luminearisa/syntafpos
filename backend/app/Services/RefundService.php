<?php

namespace App\Services;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\SaleStatus;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The refund engine: money goes back, and each tender is written down by exactly
 * the slice it gave up.
 *
 * A refund is two decisions, kept apart on purpose. The first is how much and
 * *how* — cash across the counter, the original tender reversed, a manual bank
 * transfer, or a provider reversal 3.8 will wire. The second is *which payments*
 * it comes off, and that is the allocation: a sale paid Rp 100.000 in cash and
 * Rp 100.000 by card, refunded Rp 50.000, takes Rp 25.000 off each. Without
 * allocations a multi-payment refund is a single number nobody can reconcile
 * against a bank statement; with them, the payment rows carry their own
 * `refunded_amount` and the next refund knows what is left.
 *
 * Approval is configurable and snapshotted. A refund below the shop's threshold
 * is approved by the rule itself; one at or over it waits for somebody holding
 * `refunds.approve`. The threshold the decision was made against is stored on the
 * row, because editing the setting tomorrow must not rewrite yesterday's finding.
 *
 * The money does not move until `complete()`, and it moves through
 * PaymentService::refund() — the single writer for a tender's worth. Every rule
 * about how much one payment can still give back therefore lives there, and this
 * engine only decides which payments and in what proportion.
 */
class RefundService
{
    public function __construct(
        private PaymentService $payments,
        private NumberingService $numbering,
        private SettingsService $settings,
        private AuditService $audit,
    ) {}

    /**
     * Raise a refund against a sale.
     *
     * @param  array<string, mixed>  $input
     */
    public function store(Sale $sale, User $user, array $input): Refund
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A refund needs a reason.',
            ]);
        }

        $method = $this->method($input['method'] ?? null);

        return DB::transaction(function () use ($sale, $user, $input, $reason, $method) {
            /** @var Sale $locked */
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SaleStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'sale' => "Sale {$locked->number} is cancelled; there is nothing to refund.",
                ]);
            }

            $locked->unsetRelation('payments');
            $locked->load('payments');

            $amount = $this->positiveAmount($input['amount'] ?? null);
            $refundable = $locked->refundableAmount();

            if (bccomp($amount, $refundable, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'That refund is larger than the %s still refundable on sale %s.',
                        $refundable,
                        $locked->number
                    ),
                ]);
            }

            $saleReturn = $this->resolveReturn($locked, $input['sale_return_id'] ?? null);

            $allocations = $this->allocate($locked, $amount, $input['allocations'] ?? null);

            $threshold = DecimalMath::add((string) $this->settings->get('refunds.approval_threshold', '0', $locked->company_id), '0');
            $approvalRequired = bccomp($amount, $threshold, 4) >= 0;

            $refund = Refund::create([
                'company_id' => $locked->company_id,
                'sale_id' => $locked->id,
                'sale_return_id' => $saleReturn?->id,
                'register_id' => $locked->register_id,
                'register_session_id' => $locked->register_session_id,
                'number' => $this->numbering->next('refund', $locked->company_id),
                'method' => $method,
                'status' => $approvalRequired ? RefundStatus::Requested : RefundStatus::Approved,
                'amount' => $amount,
                'currency' => $locked->currency,
                'reason' => $reason,
                'external_reference' => isset($input['external_reference']) ? trim((string) $input['external_reference']) ?: null : null,
                'approval_threshold' => $threshold,
                'approval_required' => $approvalRequired,
                'requested_by' => $user->id,
                'requested_at' => now(),
                // A refund under the threshold is approved by the shop's own
                // rule, not by a person, so no approver is named; the metadata
                // says so, and the audit trail records who raised it.
                'approved_at' => $approvalRequired ? null : now(),
                'metadata' => $approvalRequired ? null : ['auto_approved' => true],
                'notes' => isset($input['notes']) ? trim((string) $input['notes']) ?: null : null,
            ]);

            foreach ($allocations as $allocation) {
                $refund->allocations()->create([
                    'sale_payment_id' => $allocation['sale_payment_id'],
                    'company_id' => $refund->company_id,
                    'amount' => $allocation['amount'],
                    'currency' => $refund->currency,
                ]);
            }

            $this->audit->record('refund.create', 'refund', $refund->id, null, [
                'number' => $refund->number,
                'sale_number' => $locked->number,
                'amount' => $amount,
                'method' => $method->value,
                'status' => $refund->status->value,
                'approval_required' => $approvalRequired,
                'threshold' => $threshold,
                'reason' => $reason,
            ], $refund->company_id, $user->id);

            return $refund->fresh(['allocations', 'sale']);
        });
    }

    /**
     * Sign off a refund that the threshold referred to a person.
     *
     * @param  array<string, mixed>  $input
     */
    public function approve(Refund $refund, User $user, array $input = []): Refund
    {
        return $this->transition($refund, $user, RefundStatus::Approved, function (Refund $locked, string $note) use ($user) {
            $locked->forceFill([
                'status' => RefundStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'notes' => $note !== '' ? $note : $locked->notes,
            ])->save();
        }, 'refund.approve', trim((string) ($input['note'] ?? '')));
    }

    /**
     * Refuse a refund before anything moved.
     *
     * @param  array<string, mixed>  $input
     */
    public function reject(Refund $refund, User $user, array $input = []): Refund
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Rejecting a refund needs a reason.',
            ]);
        }

        return $this->transition($refund, $user, RefundStatus::Rejected, function (Refund $locked) use ($user, $reason) {
            $locked->forceFill([
                'status' => RefundStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();
        }, 'refund.reject', $reason);
    }

    /**
     * Hand an approved refund to whatever is going to pay it out.
     *
     * @param  array<string, mixed>  $input
     */
    public function process(Refund $refund, User $user, array $input = []): Refund
    {
        return $this->transition($refund, $user, RefundStatus::Processing, function (Refund $locked) {
            $locked->forceFill(['status' => RefundStatus::Processing])->save();
        }, 'refund.process');
    }

    /**
     * Pay the refund out and write the tenders down.
     *
     * This is the only place the money actually moves. Each allocation is handed
     * to PaymentService::refund(), which locks the tender and refuses anything it
     * no longer holds; the whole call is one transaction, so a refund that cannot
     * honour every allocation leaves no tender half-written.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(Refund $refund, User $user, array $input = []): Refund
    {
        return DB::transaction(function () use ($refund, $user, $input) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(RefundStatus::Completed)) {
                throw ValidationException::withMessages([
                    'refund' => "Refund {$locked->number} is {$locked->status->label()} and cannot be completed.",
                ]);
            }

            $locked->load('allocations');

            $before = $locked->status;

            foreach ($locked->allocations as $allocation) {
                $payment = SalePayment::query()->find($allocation->sale_payment_id);

                if (! $payment) {
                    throw ValidationException::withMessages([
                        'refund' => "Refund {$locked->number} points at a payment that no longer exists.",
                    ]);
                }

                $this->payments->refund(
                    $payment,
                    (string) $allocation->amount,
                    $user,
                    "Refund {$locked->number} of payment {$payment->number}",
                    $locked->id
                );
            }

            $reference = isset($input['external_reference']) ? trim((string) $input['external_reference']) : '';

            $locked->forceFill([
                'status' => RefundStatus::Completed,
                'processed_by' => $user->id,
                'processed_at' => now(),
                'external_reference' => $reference !== '' ? $reference : $locked->external_reference,
            ])->save();

            $this->audit->record('refund.complete', 'refund', $locked->id, [
                'status' => $before->value,
            ], [
                'status' => RefundStatus::Completed->value,
                'amount' => (string) $locked->amount,
                'allocations' => $locked->allocations->count(),
            ], $locked->company_id, $user->id);

            return $locked->fresh(['allocations', 'sale']);
        });
    }

    /**
     * Record that the money could not be paid out.
     *
     * @param  array<string, mixed>  $input
     */
    public function fail(Refund $refund, User $user, array $input = []): Refund
    {
        $reason = trim((string) ($input['reason'] ?? ''));

        return $this->transition($refund, $user, RefundStatus::Failed, function (Refund $locked) use ($reason) {
            $locked->forceFill([
                'status' => RefundStatus::Failed,
                'failure_reason' => $reason !== '' ? $reason : null,
            ])->save();
        }, 'refund.fail', $reason);
    }

    /**
     * Apply one guarded state change, locking the row and auditing the move.
     *
     * @param  callable(Refund, string): void  $mutate
     */
    private function transition(Refund $refund, User $user, RefundStatus $target, callable $mutate, string $action, string $note = ''): Refund
    {
        return DB::transaction(function () use ($refund, $user, $target, $mutate, $action, $note) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $before = $locked->status;

            if (! $before->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'refund' => "Refund {$locked->number} is {$before->label()} and cannot become {$target->label()}.",
                ]);
            }

            $mutate($locked, $note);

            $this->audit->record($action, 'refund', $locked->id, [
                'status' => $before->value,
            ], [
                'status' => $target->value,
                'note' => $note !== '' ? $note : null,
            ], $locked->company_id, $user->id);

            return $locked->fresh(['allocations', 'sale']);
        });
    }

    /**
     * Work out which tenders the refund comes off.
     *
     * With explicit allocations the client names them, and they are checked to
     * belong to this sale and to add up to the refund exactly. Without them the
     * engine draws from the sale's tenders oldest-first, taking each one's
     * remaining net until the refund is covered — the deterministic split a
     * report can reproduce.
     *
     * @return list<array{sale_payment_id: int, amount: string}>
     */
    private function allocate(Sale $sale, string $amount, mixed $explicit): array
    {
        if (is_array($explicit) && $explicit !== []) {
            return $this->explicitAllocations($sale, $amount, $explicit);
        }

        $remaining = $amount;
        $allocations = [];

        foreach ($sale->payments->sortBy('id') as $payment) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $refundable = $payment->netAmount();

            if (bccomp($refundable, '0', 4) <= 0) {
                continue;
            }

            $take = bccomp($remaining, $refundable, 4) < 0 ? $remaining : $refundable;

            $allocations[] = ['sale_payment_id' => $payment->id, 'amount' => $take];
            $remaining = DecimalMath::sub($remaining, $take);
        }

        if (bccomp($remaining, '0', 4) !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'That refund cannot be covered by the sale\'s settled payments.',
            ]);
        }

        return $allocations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $explicit
     * @return list<array{sale_payment_id: int, amount: string}>
     */
    private function explicitAllocations(Sale $sale, string $amount, array $explicit): array
    {
        $allocations = [];
        $total = '0';

        foreach ($explicit as $index => $row) {
            if (! is_array($row) || empty($row['sale_payment_id'])) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.sale_payment_id" => 'Every allocation needs the payment it comes off.',
                ]);
            }

            $paymentId = (int) $row['sale_payment_id'];
            $payment = $sale->payments->firstWhere('id', $paymentId);

            if (! $payment) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.sale_payment_id" => 'That payment is not part of this sale.',
                ]);
            }

            $part = $this->positiveAmount($row['amount'] ?? null);

            if (bccomp($part, $payment->netAmount(), 4) > 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => sprintf(
                        'Payment %s only has %s left to refund.',
                        $payment->number,
                        $payment->netAmount()
                    ),
                ]);
            }

            $total = DecimalMath::add($total, $part);
            $allocations[] = ['sale_payment_id' => $paymentId, 'amount' => $part];
        }

        if (bccomp($total, $amount, 4) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => 'The allocations must add up to the refund amount.',
            ]);
        }

        return $allocations;
    }

    /**
     * The return a refund refers to, proven to belong to the same sale.
     */
    private function resolveReturn(Sale $sale, mixed $id): ?SaleReturn
    {
        if ($id === null || $id === '') {
            return null;
        }

        /** @var SaleReturn|null $return */
        $return = SaleReturn::query()
            ->where('sale_id', $sale->id)
            ->whereKey((int) $id)
            ->first();

        if (! $return) {
            throw ValidationException::withMessages([
                'sale_return_id' => 'That return does not belong to this sale.',
            ]);
        }

        return $return;
    }

    private function method(mixed $raw): RefundMethod
    {
        $value = $raw instanceof RefundMethod ? $raw->value : trim((string) ($raw ?? ''));

        if ($value === '') {
            return RefundMethod::OriginalPayment;
        }

        try {
            return RefundMethod::from($value);
        } catch (\ValueError) {
            throw ValidationException::withMessages([
                'method' => "Unknown refund method {$value}.",
            ]);
        }
    }

    private function positiveAmount(mixed $raw): string
    {
        $amount = trim((string) ($raw ?? ''));

        if (! preg_match('/^\d+(\.\d{1,4})?$/', $amount) || bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A refund amount must be more than zero, with at most four decimals.',
            ]);
        }

        return DecimalMath::add($amount, '0');
    }
}
