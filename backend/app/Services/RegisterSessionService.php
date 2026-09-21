<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\EntityStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\RegisterSessionStatus;
use App\Enums\SaleStatus;
use App\Models\CashMovement;
use App\Models\Register;
use App\Models\RegisterSession;
use App\Models\User;
use App\Support\BusinessContext;
use App\Support\DecimalMath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cash register engine: open a drawer, move money through it, close it against
 * a count, and decide who has to sign off the difference.
 *
 * This service is the only thing in the app that writes a `RegisterSession` or a
 * `CashMovement`, and the only thing that answers "how much cash *should* be in
 * this drawer". Both halves carry weight. The single-writer rule is what makes a
 * variance mean anything: if a shift row could be edited elsewhere, a shortage
 * could be made to disappear without a supervisor noticing. The single
 * expected-cash method exists because the figure appears in three places — a live
 * till screen, the close itself, and the closing report — and three copies of one
 * arithmetic rule is three chances for them to disagree with each other.
 *
 * **The formula.**
 *
 *     Opening + Cash sales + Cash in − Cash refunds − Cash out = Expected
 *     Variance = Actual − Expected
 *
 * "Cash sales" is what the drawer physically kept, which is *not* the same as the
 * amount tendered on cash lines: Rp 200.000 of a bill paid with a Rp 250.000 note
 * puts Rp 250.000 in and Rp 50.000 straight back out, so the drawer is Rp 200.000
 * heavier and not Rp 250.000. The term is therefore `tendered − change` per
 * counted payment. This is 3.3's rule that change is not revenue, applied one
 * level down: change is also not a variance. Getting it the other way round would
 * make every cashier on the shop floor look like they are over-long on float by
 * exactly the change they handed back.
 *
 * **What is excluded, and why.** Membership is decided by a foreign key stamped at
 * the moment money moved, never by `BETWEEN open AND close` on timestamps: a
 * date-range sum double-counts a ticket settled across a close and, worse, lets a
 * reopened shift silently rewrite what yesterday's report said. Within a shift,
 * three things do not count — a cancelled sale (its goods came back and its money
 * went back), a payment whose own status says it is not settled (failed,
 * cancelled, refunded), and a tender that never crossed the drawer at all
 * (customer credit, and any gateway channel until 3.8 settles it). Each exclusion
 * is a way a report could claim cash the shop does not hold.
 *
 * **Expected is always recomputed.** `closing_balance` stores what the close
 * worked out, for the record and the report header, but no comparison to a count
 * reads a cached figure: a payment recorded onto a shift after it closed must show
 * up on that shift's report rather than being invisible.
 *
 * **Approval is a signature, not a state change.** A shift over threshold stays
 * closed; its drawer stays shut; approving it records who accepted the difference
 * and when. It deliberately does not touch `variance` — agreeing that a drawer was
 * Rp 40.000 short does not make it Rp 40.000 longer, and a signature that could
 * also move the figure would be indistinguishable from the error it authorises.
 */
class RegisterSessionService
{
    public function __construct(
        private AuditService $audit,
        private BusinessContext $context,
        private NumberingService $numbering,
        private SettingsService $settings,
    ) {}

    /**
     * Open a register with float in the drawer and start a shift.
     *
     * @param  array{register_id?: int|null, cashier_id?: int|null, opening_balance?: string|float|int|null, opened_at?: string|null, notes?: string|null}  $input
     */
    public function open(User $user, array $input): RegisterSession
    {
        $register = $this->resolveRegister($user, $input['register_id'] ?? null);
        $cashier = $this->resolveCashier(isset($input['cashier_id']) ? (int) $input['cashier_id'] : $user->id, $register);
        $opening = $this->positiveAmount(
            $input['opening_balance'] ?? null,
            'opening_balance',
            'Enter the float put in the drawer. Opening a register with no float is a shift nobody can reconcile.'
        );
        $openedAt = $this->resolveTimestamp($input['opened_at'] ?? null, 'opened_at');

        $this->guardNoOpenShift($register);

        try {
            return DB::transaction(function () use ($register, $cashier, $user, $opening, $openedAt, $input) {
                $session = RegisterSession::create([
                    'company_id' => $register->company_id,
                    'branch_id' => $register->branch_id,
                    'register_id' => $register->id,
                    'warehouse_id' => $register->warehouse_id,
                    'cashier_id' => $cashier->id,
                    'number' => $this->numbering->next('shift', $register->company_id),
                    'status' => RegisterSessionStatus::Open->value,
                    'register_open_key' => RegisterSession::openKeyFor($register->id),
                    'opening_balance' => $opening,
                    'opened_at' => $openedAt,
                    'opened_by' => $user->id,
                    'notes' => $this->nullableTrim($input['notes'] ?? null),
                ]);

                $this->audit->record('register_session.open', 'register_session', $session->id, null, [
                    'number' => $session->number,
                    'register' => $register->code,
                    'cashier' => $cashier->name,
                    'opening_balance' => $opening,
                    'opened_at' => $openedAt->toIso8601String(),
                ], $session->company_id, $user->id);

                return $session;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The unique index on register_open_key caught this, not the read
            // above: two cashiers tapped Open on one register together, both
            // passed the check, and only one may have a drawer. The loser gets the
            // same answer the read would have given, which is the point of having
            // the constraint at all. The exception itself is logged, since a race
            // resolved this cleanly is not an error a customer needs to read.
            report($exception);

            throw $this->alreadyOpen($register);
        }
    }

    /**
     * Record money into or out of an open drawer.
     *
     * @param  array{type?: string|CashMovementType|null, amount?: string|float|int|null, reason?: string|null, reference?: string|null, occurred_at?: string|null, notes?: string|null}  $input
     */
    public function recordMovement(RegisterSession $session, User $user, array $input): CashMovement
    {
        $this->guardOpen($session);

        $type = $this->resolveMovementType($input['type'] ?? null);
        $amount = $this->positiveAmount(
            $input['amount'] ?? null,
            'amount',
            'A cash movement needs an amount greater than zero. Money that did not move is not a movement.'
        );
        $reason = $this->requiredReason(
            $input['reason'] ?? null,
            'cash_movement.reason',
            'Give a reason: it is the only thing that will explain this money later.'
        );
        $reference = $this->resolveReference($input['reference'] ?? null);
        $occurredAt = $this->resolveTimestamp($input['occurred_at'] ?? null, 'cash_movement.occurred_at');

        if ($occurredAt->lessThan($session->opened_at)) {
            throw ValidationException::withMessages([
                'cash_movement.occurred_at' => 'This shift opened at '.$session->opened_at->format('d/m/Y H:i').'; money cannot have moved through it before then.',
            ]);
        }

        return DB::transaction(function () use ($session, $user, $type, $amount, $reason, $reference, $occurredAt, $input) {
            $movement = CashMovement::create([
                'company_id' => $session->company_id,
                'branch_id' => $session->branch_id,
                'register_id' => $session->register_id,
                'register_session_id' => $session->id,
                'user_id' => $user->id,
                'type' => $type->value,
                'amount' => $amount,
                'currency' => $this->currencyFor($session),
                'reason' => $reason,
                'reference' => $reference,
                'occurred_at' => $occurredAt,
                'notes' => $this->nullableTrim($input['notes'] ?? null),
            ]);

            $this->audit->record('cash_movement.record', 'cash_movement', $movement->id, null, [
                'shift' => $session->number,
                'type' => $type->value,
                'group' => $type->group(),
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ], $movement->company_id, $user->id);

            return $movement->load(['user:id,name', 'session:id,number,status']);
        });
    }

    /**
     * Count the drawer, take the difference against what should be there, and shut
     * the shift.
     *
     * The session is locked and its records re-read inside the transaction, so the
     * expected figure and the count describe the same drawer at the same moment —
     * a sale settling between the count being typed and saved must not be able to
     * manufacture a shortage or hide one.
     *
     * @param  array{actual_balance?: string|float|int|null, closed_at?: string|null, notes?: string|null}  $input
     */
    public function close(RegisterSession $session, User $user, array $input): RegisterSession
    {
        $actual = $this->actualCount($input['actual_balance'] ?? null);
        $closedAt = $this->resolveTimestamp($input['closed_at'] ?? null, 'closed_at');

        return DB::transaction(function () use ($session, $user, $actual, $closedAt, $input) {
            $locked = $this->lock($session);

            $this->guardOpen($locked);

            if ($closedAt->lessThan($locked->opened_at)) {
                throw ValidationException::withMessages([
                    'closed_at' => 'A shift cannot close before it opened at '.$locked->opened_at->format('d/m/Y H:i').'.',
                ]);
            }

            // A ticket that still owes money is goods that have left the counter
            // with no cash in the drawer for them, and no count can tell that apart
            // from a theft. It has to be settled or cancelled first, so the shift
            // closes on a drawer whose contents are fully explained.
            if (($open = $this->unsettledSaleCount($locked)) > 0) {
                throw ValidationException::withMessages([
                    'sales' => "This shift has {$open} sale(s) still owing money. Take the payment or cancel the sale before closing the register.",
                ]);
            }

            $summary = $this->summarise($locked);
            $threshold = $this->varianceThreshold($locked->company_id);
            $variance = DecimalMath::sub($actual, $summary['expected_cash']);

            // Strictly greater: a variance of exactly the threshold is inside what
            // this shop decided it can absorb without calling a supervisor over.
            $requires = bccomp($this->absolute($variance), $threshold, 4) > 0;

            $before = [
                'status' => $locked->status->value,
                'closing_balance' => $locked->closing_balance,
                'variance' => $locked->variance,
                'is_approved' => (bool) $locked->is_approved,
            ];

            $locked->forceFill([
                'status' => RegisterSessionStatus::Closed->value,
                // Frees the register: the next Open takes the unique key.
                'register_open_key' => null,
                'closed_at' => $closedAt,
                'closed_by' => $user->id,
                'closing_balance' => $summary['expected_cash'],
                'actual_balance' => $actual,
                'variance' => $variance,
                'variance_threshold' => $threshold,
                'requires_approval' => $requires,
                'notes' => $this->appendNote($locked->notes, $input['notes'] ?? null),
            ])->save();

            $this->audit->record('register_session.close', 'register_session', $locked->id, $before, [
                'number' => $locked->number,
                'opening_balance' => (string) $locked->opening_balance,
                'cash_sales' => $summary['cash_sales'],
                'cash_in' => $summary['cash_in'],
                'cash_refunds' => $summary['cash_refunds'],
                'cash_out' => $summary['cash_out'],
                'expected_cash' => $summary['expected_cash'],
                'actual_balance' => $actual,
                'variance' => $variance,
                'variance_threshold' => $threshold,
                'requires_approval' => $requires,
            ], $locked->company_id, $user->id);

            return $locked->fresh();
        });
    }

    /**
     * Accept a variance too big for a cashier to sign for themselves.
     *
     * @param  array{note?: string|null}  $input
     */
    public function approve(RegisterSession $session, User $approver, array $input = []): RegisterSession
    {
        return DB::transaction(function () use ($session, $approver, $input) {
            $locked = $this->lock($session);

            if (! $locked->isClosed()) {
                throw ValidationException::withMessages([
                    'approval' => 'Close and count the shift first — there is no variance to approve yet.',
                ]);
            }

            if (! $locked->requires_approval) {
                throw ValidationException::withMessages([
                    'approval' => 'This shift came in within its '.$locked->variance_threshold.' tolerance, so there is nothing to approve.',
                ]);
            }

            if ($locked->is_approved) {
                throw ValidationException::withMessages([
                    'approval' => 'Already approved by '.$locked->approvedBy?->name.' on '.$locked->approved_at?->format('d/m/Y H:i').'.',
                ]);
            }

            $locked->forceFill([
                'is_approved' => true,
                'approved_by' => $approver->id,
                'approved_at' => Carbon::now(),
                'approval_note' => $this->nullableTrim($input['note'] ?? null),
            ])->save();

            $this->audit->record('register_session.approve', 'register_session', $locked->id, null, [
                'number' => $locked->number,
                'variance' => (string) $locked->variance,
                'approver' => $approver->name,
                'note' => $locked->approval_note,
            ], $locked->company_id, $approver->id);

            return $locked->fresh();
        });
    }

    /**
     * Put a closed shift back open so work on it can continue.
     *
     * "Reopen if authorized" in full: the permission gates the route, the reason is
     * mandatory, and the reopen is counted and attributed. What it does not do is
     * erase the count that was already made — `actual_balance`, `variance` and the
     * close stay on the row until the next close supersedes them in place, so an
     * audit of the shift can still see what was reported the first time.
     *
     * The approval *is* cleared, because a signature covers the count that was
     * approved and that count is no longer the shift's last word.
     *
     * @param  array{reason?: string|null}  $input
     */
    public function reopen(RegisterSession $session, User $user, array $input = []): RegisterSession
    {
        $reason = $this->requiredReason(
            $input['reason'] ?? null,
            'reason',
            'A reopen needs a reason: this shift is about to be counted again.'
        );

        return DB::transaction(function () use ($session, $user, $reason) {
            $locked = $this->lock($session);

            if ($locked->isOpen()) {
                throw ValidationException::withMessages([
                    'reason' => 'This shift is already open.',
                ]);
            }

            $superseded = [
                'variance' => (string) $locked->variance,
                'actual_balance' => (string) $locked->actual_balance,
                'expected_cash' => (string) $locked->closing_balance,
                'was_approved' => (bool) $locked->is_approved,
            ];

            try {
                $locked->forceFill([
                    'status' => RegisterSessionStatus::Open->value,
                    'register_open_key' => RegisterSession::openKeyFor($locked->register_id),
                    'reopen_count' => $locked->reopen_count + 1,
                    'reopened_by' => $user->id,
                    'reopened_at' => Carbon::now(),
                    'reopen_reason' => $reason,
                    'is_approved' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_note' => null,
                ])->save();
            } catch (UniqueConstraintViolationException $exception) {
                // Another shift has since taken this register. Refusing is the only
                // honest answer: two open drawers on one register cannot both
                // reconcile, and this one has money already counted against it.
                // Reported rather than chained — `ValidationException` has no
                // previous parameter, and the constraint that fired is the fact a
                // developer needs, not the customer.
                report($exception);

                throw ValidationException::withMessages([
                    'reason' => $locked->register?->code.' already has another shift open. Close that one before reopening this.',
                ]);
            }

            $this->audit->record('register_session.reopen', 'register_session', $locked->id, $superseded, [
                'number' => $locked->number,
                'reopen_count' => $locked->reopen_count,
                'reason' => $reason,
            ], $locked->company_id, $user->id);

            return $locked->fresh();
        });
    }

    /**
     * Every figure the closing report shows, recomputed from the records.
     *
     * One method for the live shift screen, the close and the report endpoint, so a
     * manager comparing what the till said at close with what the report says
     * afterwards is looking at the same arithmetic both times.
     *
     * @return array<string, string|null>
     */
    public function summarise(RegisterSession $session): array
    {
        $opening = (string) $session->opening_balance;

        [$cashSales, $nonCashSales, $tenderRefunds] = $this->tenderedTotals($session);
        [$cashIn, $cashOut, $movementRefunds] = $this->movementTotals($session);

        // Two ways a refund leaves this drawer: handed back across the counter as a
        // movement, or given back against a tender the shift itself took. One report
        // line, because the drawer does not care which.
        $cashRefunds = DecimalMath::add($movementRefunds, $tenderRefunds);

        $expected = DecimalMath::add(
            $opening,
            DecimalMath::add(
                $cashSales,
                DecimalMath::sub($cashIn, DecimalMath::add($cashRefunds, $cashOut))
            )
        );

        // Null until someone counts. An open shift has no actual figure, and a zero
        // here would read on a report as "an empty drawer was counted".
        $actual = $session->actual_balance === null ? null : (string) $session->actual_balance;

        // Kept out of `$expected` (see unattributedCash) but reported beside it, so a
        // long drawer has somewhere to be explained before anyone is blamed for it.
        $loose = $this->unattributedCash($session);

        return [
            'opening_balance' => $opening,
            'cash_sales' => $cashSales,
            'non_cash_sales' => $nonCashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'cash_refunds' => $cashRefunds,
            'expected_cash' => $expected,
            'actual_balance' => $actual,
            'variance' => $actual === null ? null : DecimalMath::sub($actual, $expected),
            'sales_count' => (string) $this->countedSalesQuery($session)->count(),
            'tender_count' => (string) $this->countedPaymentsQuery($session)->count(),
            'movement_count' => (string) $session->movements()->count(),
            // Named at the far end so the summary stays the closing report the
            // resource reads out, key for key.
            'unattributed_cash' => $loose['amount'],
            'unattributed_count' => $loose['count'],
        ];
    }

    /**
     * The shift a register is working right now.
     *
     * Returns null when nobody has opened one, which is a valid state for the rest
     * of the app: a sale raised by an import or from head office has no drawer, and
     * a shift must never become a precondition for being able to sell. What a shop
     * that wants the gate can do is switch on `registers.require_open_shift`, which
     * `guardCheckoutAllowed` enforces.
     */
    public function currentFor(?int $registerId, ?int $companyId = null): ?RegisterSession
    {
        if ($registerId === null) {
            return null;
        }

        return RegisterSession::query()
            ->where('register_id', $registerId)
            ->where('company_id', $companyId ?? $this->context->companyId())
            ->where('status', RegisterSessionStatus::Open->value)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Refuse a till sale on a register that is not open, where the shop asks for it.
     *
     * Off by default and on per company, because the two shapes are both real: a
     * single-owner stall that counts its drawer at the end of the day, and a chain
     * that will not accept a sale into the books unless someone has taken
     * responsibility for the cash it lands in. Enforcement belongs here rather than
     * in the request layer because it is a rule about money in a drawer, and a
     * client must not be able to talk its way past it.
     */
    public function guardCheckoutAllowed(int $companyId, ?int $registerId): void
    {
        if (! $this->settings->get('registers.require_open_shift', false, $companyId)) {
            return;
        }

        if ($this->currentFor($registerId, $companyId)) {
            return;
        }

        throw ValidationException::withMessages([
            'register' => $registerId === null
                ? 'Select a register on the till and open it before taking a sale.'
                : 'This register is not open. Open it with its float before taking a sale.',
        ]);
    }

    /**
     * Cash taken on this shift's register that belongs to no shift at all.
     *
     * The gap a shop needs to see rather than infer: a sale rung up while nobody had
     * opened the register is real money in a real drawer, and if the shift report
     * stayed silent about it the count comes out long and the difference gets blamed
     * on the cashier. Reported next to the shift so the two can be read together, and
     * deliberately not folded into `expected_cash` — that sum is what *this* shift
     * holds, and borrowing a neighbour's takings to explain a variance is how
     * reconciliations start lying.
     *
     * @return array{count: string, amount: string}
     */
    public function unattributedCash(RegisterSession $session): array
    {
        $row = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereNull('sale_payments.register_session_id')
            ->whereNull('sales.register_session_id')
            ->where('sale_payments.register_id', $session->register_id)
            ->where('sale_payments.channel', PaymentChannel::Cash->value)
            ->whereIn('sale_payments.status', [
                PaymentStatus::Paid->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('sales.deleted_at')
            // Bounded by the shift's own lifetime, so cash from a week before is not
            // pulled into this drawer's business.
            ->whereBetween('sale_payments.created_at', [
                $session->opened_at,
                $session->closed_at ?? Carbon::now(),
            ])
            ->selectRaw('COUNT(*) as tally, COALESCE(SUM(sale_payments.tendered - sale_payments.change - sale_payments.refunded_amount), 0) as kept')
            ->first();

        return [
            'count' => (string) ($row->tally ?? 0),
            'amount' => bcadd((string) ($row->kept ?? '0'), '0', 4),
        ];
    }

    /**
     * The tolerance a variance is judged against, in minor units of the ledger.
     */
    public function varianceThreshold(?int $companyId): string
    {
        $raw = trim((string) $this->settings->get('registers.variance_threshold', '0', $companyId));

        return is_numeric($raw) ? bcadd($raw, '0', 4) : '0.0000';
    }

    /**
     * Tenders this shift is accountable for, with the sales they settled attached.
     *
     * The exclusions from the class header, in one place: a payment must belong to
     * this shift, must be settled in its own right, and must not belong to a sale
     * that has since been withdrawn.
     */
    private function countedPaymentsQuery(RegisterSession $session)
    {
        return DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.register_session_id', $session->id)
            ->whereIn('sale_payments.status', [
                PaymentStatus::Paid->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('sales.deleted_at');
    }

    /**
     * @return array{0: string, 1: string, 2: string} cash kept, non-cash settled, cash handed back on tenders
     */
    private function tenderedTotals(RegisterSession $session): array
    {
        $rows = $this->countedPaymentsQuery($session)
            ->selectRaw('channel, SUM(amount) as amount, SUM(tendered) as tendered, SUM(change) as change_given, SUM(refunded_amount) as refunded')
            ->groupBy('channel')
            ->get();

        $cash = '0.0000';
        $nonCash = '0.0000';
        $refunded = '0.0000';

        foreach ($rows as $row) {
            $channel = PaymentChannel::tryFrom((string) $row->channel);

            // An unknown channel is counted as no cash rather than as some: money
            // this engine cannot name is money it must not claim to be holding.
            if ($channel === null) {
                continue;
            }

            if ($channel->usesCustomerAccount()) {
                continue;
            }

            if ($channel->takesTender()) {
                $tendered = bcadd((string) $row->tendered, '0', 4);
                $givenBack = bcadd((string) $row->change_given, '0', 4);

                $cash = DecimalMath::add($cash, DecimalMath::sub($tendered, $givenBack));

                // Money handed back against a tender left this drawer too, and the
                // payment engine already knows how much: `refunded_amount` is what a
                // refund of a cash tender was. It joins the report's refund line
                // rather than reducing cash sales, because the work order's formula
                // subtracts each thing once — `Cash sales − Cash refunds` — and
                // netting it here as well would take the same money off twice.
                $refunded = DecimalMath::add($refunded, bcadd((string) $row->refunded, '0', 4));

                continue;
            }

            $nonCash = DecimalMath::add($nonCash, bcadd((string) $row->amount, '0', 4));
        }

        return [$cash, $nonCash, $refunded];
    }

    /**
     * @return array{0: string, 1: string, 2: string} cash in, cash out, refunds paid
     */
    private function movementTotals(RegisterSession $session): array
    {
        // The query builder rather than the relation: `$session->movements()` returns
        // models, and a grouped aggregate over models hands back a `type` attribute
        // with the enum cast already applied — which then cannot be read as the
        // string this method needs in order to look the group up.
        $rows = DB::table('cash_movements')
            ->where('register_session_id', $session->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get();

        $in = '0.0000';
        $out = '0.0000';
        $refunds = '0.0000';

        foreach ($rows as $row) {
            $total = bcadd((string) $row->total, '0', 4);
            $group = CashMovementType::tryFrom((string) $row->type)?->group() ?? 'out';

            if ($group === 'in') {
                $in = DecimalMath::add($in, $total);
            } elseif ($group === 'refund') {
                $refunds = DecimalMath::add($refunds, $total);
            } else {
                $out = DecimalMath::add($out, $total);
            }
        }

        return [$in, $out, $refunds];
    }

    private function countedSalesQuery(RegisterSession $session)
    {
        return DB::table('sales')
            ->where('sales.register_session_id', $session->id)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->whereNull('sales.deleted_at');
    }

    /**
     * Non-cancelled sales on this shift that still have money owing on them.
     *
     * Judged by the status the payment engine already derives rather than by
     * comparing `paid_total` to `grand_total` here as well: two places deciding
     * what "settled" means is how a sale ends up blocking a close it had already
     * satisfied, or the other way round. The money test behind it is only for the
     * zero-value ticket the engine leaves in Draft — nothing owed on it, so nothing
     * to stop a drawer closing.
     */
    private function unsettledSaleCount(RegisterSession $session): int
    {
        return (int) $this->countedSalesQuery($session)
            ->whereNotIn('sales.status', [
                SaleStatus::Paid->value,
                SaleStatus::Completed->value,
            ])
            ->whereColumn('sales.grand_total', '>', 'sales.paid_total')
            ->count();
    }

    private function resolveRegister(User $user, ?int $registerId): Register
    {
        $registerId ??= $this->context->registerId();

        if ($registerId === null) {
            throw ValidationException::withMessages([
                'register_id' => 'Choose which register to open, or pick one on the till first.',
            ]);
        }

        $register = Register::query()->visibleTo($user)->find($registerId);

        if (! $register) {
            throw ValidationException::withMessages([
                'register_id' => 'That register is not one you work on.',
            ]);
        }

        if ((int) $register->company_id !== (int) $this->context->companyId()) {
            throw ValidationException::withMessages([
                'register_id' => 'That register belongs to another company.',
            ]);
        }

        if ($register->status === EntityStatus::Inactive) {
            throw ValidationException::withMessages([
                'register_id' => $register->code.' is inactive and cannot take business.',
            ]);
        }

        return $register;
    }

    private function resolveCashier(int $cashierId, Register $register): User
    {
        $cashier = User::find($cashierId);

        if (! $cashier) {
            throw ValidationException::withMessages(['cashier_id' => 'That cashier does not exist.']);
        }

        // The work order lists Cashier as an input, taken seriously: a shift cannot
        // be attributed to someone with no access to the register it is worked on,
        // because the conversation about a variance is held with that person.
        $onRegister = $register->users()->where('users.id', $cashier->id)->exists();
        $inCompany = $cashier->companies()->where('companies.id', $register->company_id)->exists();

        if (! $onRegister && ! $inCompany) {
            throw ValidationException::withMessages([
                'cashier_id' => $cashier->name.' does not have access to '.$register->code.'.',
            ]);
        }

        return $cashier;
    }

    private function guardNoOpenShift(Register $register): void
    {
        $existing = RegisterSession::query()
            ->where('register_id', $register->id)
            ->where('status', RegisterSessionStatus::Open->value)
            ->exists();

        if ($existing) {
            throw $this->alreadyOpen($register);
        }
    }

    /**
     * The refusal both duplicate-open paths answer with.
     *
     * No `previous` is carried: `ValidationException::withMessages()` has nowhere to
     * put one, so the caller that was provoked by the unique index logs the
     * constraint it hit before raising this.
     */
    private function alreadyOpen(Register $register): ValidationException
    {
        $existing = RegisterSession::query()
            ->where('register_id', $register->id)
            ->where('status', RegisterSessionStatus::Open->value)
            ->with('cashier:id,name')
            ->orderBy('opened_at')
            ->first();

        return ValidationException::withMessages([
            'register_id' => $existing
                ? $register->code.' is already open on shift '.$existing->number.' ('.$existing->cashier?->name.', since '.$existing->opened_at->format('d/m/Y H:i').'). Close it before opening another.'
                : $register->code.' already has a shift open. Close it before opening another.',
        ]);
    }

    private function guardOpen(RegisterSession $session): void
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages([
                'session' => 'Shift '.$session->number.' is closed. Reopen it, or work the register next shift.',
            ]);
        }
    }

    private function lock(RegisterSession $session): RegisterSession
    {
        return RegisterSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function resolveMovementType(string|CashMovementType|null $type): CashMovementType
    {
        $resolved = $type instanceof CashMovementType
            ? $type
            : CashMovementType::tryFrom(trim((string) $type));

        if ($resolved) {
            return $resolved;
        }

        $allowed = implode(', ', array_map(
            fn (CashMovementType $case) => $case->value,
            CashMovementType::cases()
        ));

        throw ValidationException::withMessages([
            'cash_movement.type' => "Choose a cash movement type: {$allowed}.",
        ]);
    }

    private function positiveAmount(mixed $value, string $key, string $message): string
    {
        $amount = trim((string) ($value ?? ''));

        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([$key => $message]);
        }

        return bcadd($amount, '0', 4);
    }

    /**
     * The counted figure. Zero is a legitimate count — an empty drawer is a fact —
     * so this is the one amount not required to be positive.
     */
    private function actualCount(mixed $value): string
    {
        $amount = trim((string) ($value ?? ''));

        if ($amount === '' || ! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'actual_balance' => 'Enter the cash counted in the drawer, or 0 if it is empty.',
            ]);
        }

        if (bccomp($amount, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'actual_balance' => 'A drawer cannot be counted as negative.',
            ]);
        }

        return bcadd($amount, '0', 4);
    }

    private function requiredReason(mixed $reason, string $key, ?string $message = null): string
    {
        $trimmed = trim((string) ($reason ?? ''));

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                $key => $message ?? 'Give a reason: it is the only thing that will explain this later.',
            ]);
        }

        if (strlen($trimmed) > 500) {
            throw ValidationException::withMessages([$key => 'A reason is at most 500 characters.']);
        }

        return $trimmed;
    }

    private function resolveReference(mixed $reference): ?string
    {
        $trimmed = trim((string) ($reference ?? ''));

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 128) {
            throw ValidationException::withMessages([
                'cash_movement.reference' => 'A reference is at most 128 characters.',
            ]);
        }

        return $trimmed;
    }

    private function resolveTimestamp(mixed $value, string $key): Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$key => 'That date could not be read.']);
        }
    }

    /**
     * bcmath has no abs; the comparison that decides an approval cannot be wrong
     * about which side of the threshold a shortage falls on.
     */
    private function absolute(string $value): string
    {
        return bccomp($value, '0', 4) < 0 ? bcsub('0', $value, 4) : $value;
    }

    private function currencyFor(RegisterSession $session): string
    {
        $currency = (string) $this->settings->get('company.currency', 'IDR', $session->company_id);

        return strlen($currency) === 3 ? strtoupper($currency) : 'IDR';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function appendNote(?string $existing, mixed $added): ?string
    {
        $added = $this->nullableTrim($added);

        if ($added === null) {
            return $existing;
        }

        return $existing === null ? $added : $existing.PHP_EOL.$added;
    }
}
