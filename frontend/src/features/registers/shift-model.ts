import type { CashMovementGroup, CashMovementType } from '@/types';
import { formatMoneyString } from '@/utils/format';
import { moneyText, moneyUnits } from '@/features/sales/money-math';

/**
 * The shift vocabulary the till needs (Phase 3.4).
 *
 * The six reasons are the backend's, not a preference: it rejects anything else, so
 * the form offers exactly these and never a free-text direction. What is filed here
 * is the presentation of them — label, icon, which side of the drawer they sit on —
 * because those three must agree with the enum's own `group`/`sign`, and a client
 * that guessed would colour a movement the report then counts the other way.
 */
export interface CashMovementOption {
  value: CashMovementType;
  label: string;
  group: CashMovementGroup;
  icon: string;
  /** The sentence that tells a cashier what this reason is for, in their words. */
  hint: string;
}

export const CASH_MOVEMENT_OPTIONS: CashMovementOption[] = [
  {
    value: 'cash_injection',
    label: 'Cash injection',
    group: 'in',
    icon: 'add-circle-outline',
    hint: 'Money put into the drawer to make change — from head office or another till.',
  },
  {
    value: 'other_income',
    label: 'Other income',
    group: 'in',
    icon: 'trending-up-outline',
    hint: 'Cash taken that was not a sale — a deposit, a packaging charge, a fine.',
  },
  {
    value: 'expense',
    label: 'Expense',
    group: 'out',
    icon: 'receipt-outline',
    hint: 'A bill paid out of the drawer — delivery, cleaning, supplies bought here.',
  },
  {
    value: 'withdrawal',
    label: 'Withdrawal',
    group: 'out',
    icon: 'arrow-down-circle-outline',
    hint: 'Cash lifted for the bank or for management, leaving the till.',
  },
  {
    value: 'petty_cash',
    label: 'Petty cash',
    group: 'out',
    icon: 'wallet-outline',
    hint: 'Small change paid out for something that has no invoice behind it.',
  },
  {
    value: 'refund',
    label: 'Cash refund',
    group: 'refund',
    icon: 'return-down-back-outline',
    hint: 'Money handed back to a customer, recorded here rather than on the ticket.',
  },
];

/** Options for one side of the drawer, so a "Cash in" dialog cannot offer a reason that leaves it. */
export function movementOptionsFor(group: 'in' | 'out'): CashMovementOption[] {
  return CASH_MOVEMENT_OPTIONS.filter((option) =>
    group === 'in' ? option.group === 'in' : option.group !== 'in'
  );
}

export function movementOption(type: CashMovementType | string): CashMovementOption | undefined {
  return CASH_MOVEMENT_OPTIONS.find((option) => option.value === type);
}

/**
 * Whether a variance is too big for a cashier to sign for.
 *
 * Mirrors the server's comparison for the preview only. The rule that decides is
 * evaluated at close against the shop's threshold, and a client cannot talk it out
 * of it: `CloseRegisterRequest` accepts a count, not a verdict.
 */
export function isOverThreshold(variance: string | null, threshold: string | null): boolean {
  if (variance === null) {
    return false;
  }

  const allowed = Math.abs(moneyUnits(threshold));
  const difference = Math.abs(moneyUnits(variance));

  // A threshold nobody has set is zero, and zero tolerance means any difference at
  // all needs a supervisor — which is the same reading the backend applies.
  return difference > allowed;
}

/** The close dialog's live preview: what the count implies before it is submitted. */
export interface CountPreview {
  expected: string;
  actual: string;
  variance: string;
  short: boolean;
  over: boolean;
  exact: boolean;
  needsApproval: boolean;
}

export function previewCount(
  expected: string | null,
  actual: string,
  threshold: string | null
): CountPreview {
  const expectedUnits = moneyUnits(expected);
  const actualUnits = moneyUnits(actual);
  const variance = moneyText(actualUnits - expectedUnits);
  const difference = moneyUnits(variance);

  return {
    expected: expected ?? '0',
    actual: actual || '0',
    variance,
    short: difference < 0,
    over: difference > 0,
    exact: difference === 0,
    needsApproval: isOverThreshold(variance, threshold),
  };
}

/**
 * The one-line statement of the formula, used as the hint under an expected figure.
 *
 * Kept as text rather than as maths the client performs, so a cashier reading it is
 * reading the rule the server applies rather than a translation of it.
 */
export const EXPECTED_CASH_FORMULA =
  'Opening cash + cash sales + cash in − cash refunds − cash out';

/**
 * A `datetime-local` value in the browser's own clock.
 *
 * Opening and closing a shift are statements about when the drawer was actually
 * handled, which is a wall-clock fact rather than a UTC one — and the backend's
 * `date` rule parses this form as the shop's timezone, the same way a sale's date
 * is taken. Seconds are dropped because the control itself does not show them.
 */
export function localDateTimeValue(value?: string | Date | null): string {
  const date = value === undefined || value === null ? new Date() : new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const pad = (part: number) => String(part).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours()
  )}:${pad(date.getMinutes())}`;
}

/** How long a shift has run, phrased the way a handover reads it. */
export function formatDuration(minutes: number | null | undefined): string {
  if (!minutes || minutes <= 0) {
    return '-';
  }

  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  if (hours === 0) {
    return `${rest}m`;
  }

  return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/**
 * A variance as signed money, because a ledger string is not what a count reads as.
 *
 * The stored value is a DECIMAL(20,4) string, so `-5000.0000` is the same shortage
 * a cashier just saw previewed as `Rp 5.000`. The sign is kept and put in front of
 * the formatted amount: a drawer short by 5.000 must not read as one long by it,
 * and "−" is the difference between an apology and a mystery.
 */
export function formatVariance(value: string | null | undefined): string {
  if (value === null || value === undefined) {
    return '—';
  }

  const units = moneyUnits(value);

  if (units === 0) {
    return formatMoneyString('0');
  }

  const magnitude = formatMoneyString(units < 0 ? value.slice(1) : value);

  return units < 0 ? `−${magnitude}` : `+${magnitude}`;
}
