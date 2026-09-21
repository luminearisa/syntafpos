import type { PaymentMethod, SalePaymentInput } from '@/types';
import { addMoney, isSettled, moneyText, moneyUnits, subMoney } from './money-math';

/**
 * How a tender is held in the dialog before it is sent.
 *
 * The line carries the shop's configured method itself rather than an id, because
 * the three things the row needs to decide are all read off it: whether to open a
 * cash box (`takes_tender`), whether to insist on a reference (`requires_reference`),
 * and what to print. An id would mean a second lookup for each of those, in a
 * dialog whose whole job is not getting the money wrong.
 *
 * `tendered` is cash-only: the server computes change from the difference between
 * what was handed over and what was paid, and refuses to treat any other method as
 * tendered money. Keeping it a string means the figure the cashier typed is the
 * figure that goes out, unchanged.
 */
export interface TenderLine {
  key: string;
  method: PaymentMethod | null;
  /**
   * Whether a person picked the method.
   *
   * Rows start unpicked so the list can adopt its default when it answers; once
   * someone has chosen — including choosing "no method yet" — the auto-pick must
   * stop overwriting them.
   */
  chosen: boolean;
  amount: string;
  tendered: string;
  reference: string;
}

let tenderKeySeed = 0;

export function nextTenderKey(): string {
  tenderKeySeed += 1;

  return `tender-${tenderKeySeed}`;
}

/**
 * A blank tender on a method the till has been offered.
 *
 * `amount` is what the line is settling; `tendered` starts empty even for cash, so
 * a cashier who types nothing but the amount pays exactly and gets no change.
 */
export function newTender(
  method: PaymentMethod | null,
  amount = '',
  over: Partial<TenderLine> = {}
): TenderLine {
  return {
    key: nextTenderKey(),
    method,
    chosen: method !== null,
    amount,
    tendered: '',
    reference: '',
    ...over,
  };
}

const NO_METHODS: PaymentMethod[] = [];

/**
 * The fetched list, or one stable empty array.
 *
 * A dialog derives its money summary from this in a `useMemo`, so the fallback has
 * to be the same empty array every render — a fresh `[]` would make the memo fire
 * on every keystroke and the lint rule would rightly complain.
 */
export function availableMethods(data: PaymentMethod[] | undefined): PaymentMethod[] {
  return data ?? NO_METHODS;
}

/**
 * Rows a dialog holds before the method list has arrived, with the default filled
 * in once it has.
 *
 * A tender line is seeded with no method because the till opens the moment the cart
 * does, and the request for the shop's methods may not have answered yet. Rather
 * than a spinner in front of the money, an unset row silently adopts the default as
 * soon as it is known — and an explicit choice by the cashier is never overwritten.
 */
export function withDefaultMethods(
  tenders: TenderLine[],
  methods: PaymentMethod[]
): TenderLine[] {
  const fallback = defaultTenderMethod(methods);

  return tenders.map((tender) =>
    tender.method || tender.chosen || !fallback
      ? tender
      : { ...tender, method: fallback, chosen: false }
  );
}

/**
 * A row patch that records who chose the method.
 *
 * The select's change goes through here so `chosen` cannot drift from reality: a
 * cashier who deliberately leaves "Payment method…" picked must not have the
 * shop's default typed back in by the auto-fill.
 */
export function applyTenderPatch(
  tender: TenderLine,
  change: Partial<TenderLine>
): TenderLine {
  return 'method' in change
    ? { ...tender, ...change, chosen: change.method !== null }
    : { ...tender, ...change };
}

/**
 * The method a till opens on: the shop's default, or its first one.
 *
 * A fallback row (no id — the catalogue defaults a shop has never configured) is
 * as good an answer as a configured one, which is the point: a new shop still has
 * to be able to take cash.
 */
export function defaultTenderMethod(methods: PaymentMethod[]): PaymentMethod | null {
  return methods.find((method) => method.is_default) ?? methods[0] ?? null;
}

/**
 * The money state the dialog reports, all derived from the total it is handed.
 *
 * The caller passes the server's figure — a cart's `grand_total`, or an unpaid
 * sale's balance when the same row UI settles a parked ticket.
 *
 * `over` is not a warning the cashier can ignore: the backend refuses a payment
 * above the remaining balance, so the Pay button goes away rather than the sale
 * going through short.
 */
export interface TenderSummary {
  total: string;
  paid: string;
  balance: string;
  change: string;
  settled: boolean;
  over: boolean;
  empty: boolean;
}

export function summarise(total: string, tenders: TenderLine[]): TenderSummary {
  const paid = addMoney(...tenders.map((tender) => tender.amount));
  const change = addMoney(
    ...tenders.map((tender) => subMoney(tender.tendered, tender.amount))
  );

  return {
    total,
    paid,
    balance: subMoney(total, paid),
    change: moneyText(Math.max(moneyUnits(change), 0)),
    settled: isSettled(total, paid),
    over: moneyUnits(paid) > moneyUnits(total),
    empty: moneyUnits(paid) === 0,
  };
}

/**
 * A tender the server would refuse on its face, or null if it is fine.
 *
 * Deliberately only the checks the till can answer without the sale: the balance
 * rules belong to the server, which is the only thing that can see the lock and the
 * rows already taken. This stops a cashier pressing Pay on a line that cannot work
 * — a scan with no reference, a method still loading — instead of reading a 422.
 */
export function tenderProblem(tender: TenderLine): string | null {
  if (moneyUnits(tender.amount) <= 0) {
    return null;
  }

  if (!tender.method) {
    return 'Choose how this payment was made.';
  }

  if (tender.method.requires_reference && tender.reference.trim() === '') {
    return `${tender.method.name} needs a reference — a transfer or terminal slip number.`;
  }

  if (
    !tender.method.takes_tender &&
    moneyUnits(tender.tendered) > moneyUnits(tender.amount)
  ) {
    return `${tender.method.name} cannot be over-paid: the terminal or app already moved the exact figure.`;
  }

  return null;
}

export function tenderProblems(tenders: TenderLine[]): string[] {
  return Array.from(
    new Set(
      tenders
        .map(tenderProblem)
        .filter((problem): problem is string => problem !== null)
    )
  );
}

/**
 * What the till sends.
 *
 * `payment_method_id` names the configured row when there is one; `channel` goes
 * with it so a fallback row — a shop that has configured nothing — still says what
 * kind of money this was. `tendered` only for the methods that actually take cash,
 * and a blank reference is left out rather than sent as null so the server's own
 * default for the method is what applies.
 */
export function toPaymentInput(tender: TenderLine): SalePaymentInput {
  const amount = tender.amount.trim();
  const method = tender.method;

  return {
    ...(method ? { payment_method_id: method.id } : {}),
    ...(method ? { channel: method.channel } : {}),
    amount,
    ...(method?.takes_tender && moneyUnits(tender.tendered) > moneyUnits(amount)
      ? { tendered: tender.tendered }
      : {}),
    ...(tender.reference.trim() === '' ? {} : { reference: tender.reference.trim() }),
  };
}

export function paymentsPayload(tenders: TenderLine[]): SalePaymentInput[] {
  return tenders
    .filter((tender) => moneyUnits(tender.amount) > 0)
    .map(toPaymentInput);
}

/** The line a receipt or a sale row shows: what the shop called this money. */
export function paymentLabel(payment: {
  method_name: string;
  channel_label: string;
}): string {
  return payment.method_name || payment.channel_label;
}
