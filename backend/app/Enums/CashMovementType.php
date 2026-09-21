<?php

namespace App\Enums;

/**
 * The kinds of money a drawer sees that are not a sale.
 *
 * Grouped by the line the shift report shows them on, because the work order's
 * reconciliation is a signed sum over exactly these groups:
 *
 *     Opening + Cash sales + Cash in − Cash refunds − Cash out = Expected
 *
 * Three groups rather than one direction flag, because "a refund" and "an
 * expense" are the same physical action — cash leaving the drawer — and are *not*
 * the same fact. An expense is money the business spent; a refund is money the
 * business handed back against a sale, and folding it into expenses would make
 * the takings report look as though the shop had bought something. The direction
 * is derived from the group, never stored, so adding a reason cannot introduce a
 * sign that disagrees with its own report line.
 *
 * Every case a cashier can pick is listed; the till offers no free-text direction
 * because a movement whose sign depends on how someone typed a reason is a
 * movement that cannot be reconciled.
 */
enum CashMovementType: string
{
    // Cash In
    case CashInjection = 'cash_injection';
    case OtherIncome = 'other_income';

    // Cash Out
    case Expense = 'expense';
    case Withdrawal = 'withdrawal';
    case PettyCash = 'petty_cash';

    // Cash Refund — money back to a customer, out of this drawer
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::CashInjection => 'Cash injection',
            self::OtherIncome => 'Other income',
            self::Expense => 'Expense',
            self::Withdrawal => 'Withdrawal',
            self::PettyCash => 'Petty cash',
            self::Refund => 'Cash refund',
        };
    }

    /**
     * The report line this movement is counted on.
     *
     * `in`, `out` and `refund` are the three the shift report breaks out; the
     * expected-cash sum and the shift-close audit both group by this rather than
     * by the individual reason, so a seventh reason added later needs no change
     * to the arithmetic.
     */
    public function group(): string
    {
        return match ($this) {
            self::CashInjection, self::OtherIncome => 'in',
            self::Expense, self::Withdrawal, self::PettyCash => 'out',
            self::Refund => 'refund',
        };
    }

    /**
     * Whether the movement puts money into the drawer.
     */
    public function isInflow(): bool
    {
        return $this->group() === 'in';
    }

    /**
     * The signed amount this movement contributes to expected cash.
     *
     * A plain +1/−1 so the reconciliation is a multiply-and-add over one column
     * rather than a branch on the reason in three different places.
     */
    public function sign(): int
    {
        return $this->isInflow() ? 1 : -1;
    }
}
