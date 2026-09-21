import type { PaymentMethod, SalePaymentInput } from '@/types';
import { addMoney, isSettled, moneyText, moneyUnits, subMoney } from './money-math';

/**
 * How a tender is held in the dialog before it is sent.
 *
 * `tendered` is cash-only: the server computes change from the difference between
 * what was handed over and what was paid, and refuses to treat any other method as
 * tendered money. Keeping it a string means the figure the cashier typed is the
 * figure that goes out, unchanged.
 */
export interface TenderLine {
  key: string;
  method: PaymentMethod;
  amount: string;
  tendered: string;
}

let tenderKeySeed = 0;

export function nextTenderKey(): string {
  tenderKeySeed += 1;

  return `tender-${tenderKeySeed}`;
}

/** The methods a counter takes today. Card channels stay listed but unwired until 3.8. */
export const TENDER_METHODS: Array<{ value: PaymentMethod; label: string; icon: string }> = [
  { value: 'cash', label: 'Cash', icon: 'cash-outline' },
  { value: 'card', label: 'Card', icon: 'card-outline' },
  { value: 'debit', label: 'Debit', icon: 'fitness-outline' },
  { value: 'credit', label: 'Credit card', icon: 'credit-card-outline' },
  { value: 'wallet', label: 'E-wallet', icon: 'wallet-outline' },
  { value: 'transfer', label: 'Transfer', icon: 'swap-horizontal-outline' },
];

export function methodLabel(method: PaymentMethod): string {
  return TENDER_METHODS.find((entry) => entry.value === method)?.label ?? method;
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

/** What the till sends: `tendered` only for the methods that actually take cash. */
export function toPaymentInput(tender: TenderLine): SalePaymentInput {
  const amount = tender.amount.trim();

  return {
    method: tender.method,
    amount,
    ...(tender.method === 'cash' && moneyUnits(tender.tendered) > moneyUnits(amount)
      ? { tendered: tender.tendered }
      : {}),
  };
}

export function paymentsPayload(tenders: TenderLine[]): SalePaymentInput[] {
  return tenders
    .filter((tender) => moneyUnits(tender.amount) > 0)
    .map(toPaymentInput);
}

