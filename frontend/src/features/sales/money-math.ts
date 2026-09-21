/**
 * Fixed-point money maths for the payment screen (Phase 3.2).
 *
 * The till adds and subtracts tenders while the cashier types, so it needs the
 * same two operations the server has. Doing them on JS numbers is how a drawer
 * ends up 0.01 short of a total, so amounts are scaled to an integer count of
 * ten-thousandths — the column's own scale — and only ever handled as integers.
 *
 * What this produces is a *preview*: the sale's totals come from the server's
 * money engine regardless, and checkout re-reads the cart and recomputes every
 * figure. A till that trusted these numbers over the answer would be a second
 * opinion nobody asked for.
 */

const SCALE = 10_000;

/** Anything above this would lose precision as a double, so it is not money. */
const MAX_UNITS = Number.MAX_SAFE_INTEGER;

/**
 * Parse a decimal amount into whole ten-thousandths.
 *
 * A malformed input is 0 rather than NaN: a field mid-typing must not poison the
 * balance line, and the server is the one that ultimately rejects it.
 */
export function moneyUnits(value: string | number | null | undefined): number {
  const text = String(value ?? '').trim();

  if (!/^-?\d+(\.\d{1,4})?$/.test(text)) {
    return 0;
  }

  const negative = text.startsWith('-');
  const [whole = '0', fraction = ''] = (negative ? text.slice(1) : text).split('.');
  const scaled = Number(whole) * SCALE + Number((fraction + '0000').slice(0, 4));

  if (!Number.isSafeInteger(scaled) || scaled > MAX_UNITS) {
    return 0;
  }

  return negative ? -scaled : scaled;
}

/** Back into a decimal string, without the trailing zeros an input dislikes. */
export function moneyText(units: number): string {
  const negative = units < 0;
  const abs = Math.abs(units);
  const whole = Math.floor(abs / SCALE);
  const fraction = String(abs % SCALE).padStart(4, '0').replace(/0+$/, '');
  const text = fraction ? `${whole}.${fraction}` : String(whole);

  return negative ? `-${text}` : text;
}

export function addMoney(...values: Array<string | number | null | undefined>): string {
  return moneyText(values.reduce<number>((total, value) => total + moneyUnits(value), 0));
}

export function subMoney(
  from: string | number | null | undefined,
  to: string | number | null | undefined
): string {
  return moneyText(moneyUnits(from) - moneyUnits(to));
}

/** True when a tender covers the bill exactly — the gate on the Pay button. */
export function isSettled(total: string, paid: string): boolean {
  return moneyUnits(paid) === moneyUnits(total);
}

/** Round cash notes, largest first: a drawer hands change from these. */
export const CASH_NOTES = [100_000, 50_000, 20_000, 10_000, 5_000, 2_000, 1_000];

/**
 * The next whole note up from an amount, which is what a customer actually hands
 * over when they have no coins: 118.750 becomes 120.000.
 *
 * The smallest note is skipped on purpose: it always returns the amount itself,
 * so it is not a rounding option but the bill.
 */
export function roundUpToNote(amount: string | number): string {
  const units = moneyUnits(amount);

  if (units <= 0) {
    return '0';
  }

  for (const note of CASH_NOTES.slice(0, -1)) {
    const whole = Math.ceil(units / (note * SCALE)) * note * SCALE;

    // A note is only a sensible suggestion when it does not more than double the
    // bill; beyond that the cashier is counting a different stack of money.
    if (whole <= units * 2) {
      return moneyText(whole);
    }
  }

  return moneyText(units);
}
