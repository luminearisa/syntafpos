import { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { PosCart, Sale } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { cn, formatMoneyString } from '@/utils/format';
import { useToast } from '@/components/ui/toast';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { CART_QUERY_KEY } from '@/features/pos/use-pos-cart';
import { moneyUnits, subMoney } from './money-math';
import {
  nextTenderKey,
  paymentsPayload,
  summarise,
  type TenderLine,
} from './tender-model';
import { TenderRow } from './tenders';
import { PrintButtons } from './print-buttons';
import { useReceiptPrinter } from './print';

/**
 * F10 — take the money (Phase 3.2).
 *
 * The dialog does one thing no other screen on the till may do: decide that a
 * cart is now a sale. It sends the cart id and the tenders and nothing else —
 * no total, no line, no price. The server re-reads the cart, recomputes
 * everything through the money engine, and either posts the whole transaction
 * (numbered invoice, stock out, payments) or none of it. The balance line here
 * is a live preview of that, so the cashier can see the ticket going to zero
 * while typing, not so the app gets to choose the amount.
 *
 * Two screens, keyed on whether a sale came back: cash entry, then the issued
 * screen with the invoice number, the change to hand back and the print controls.
 * A ticket that exists is not dismissible by accident — the way out is the
 * explicit New sale, which is also what clears the till.
 *
 * The entry form is keyed on the cart rather than reset from an effect: starting
 * a fresh cart must not be able to carry half-typed cash into the next customer's
 * sale, and remounting says that louder than clearing state on open does.
 */
export function CheckoutDialog({
  open,
  onClose,
  cart,
}: {
  open: boolean;
  onClose: () => void;
  cart: PosCart;
}) {
  const [issued, setIssued] = useState<Sale | null>(null);

  if (!open) {
    return null;
  }

  if (issued) {
    return (
      <IssuedSale
        sale={issued}
        onAnother={() => {
          setIssued(null);
          onClose();
        }}
      />
    );
  }

  return <CheckoutForm key={cart.id} cart={cart} onIssued={setIssued} onClose={onClose} />;
}

function CheckoutForm({
  cart,
  onIssued,
  onClose,
}: {
  cart: PosCart;
  onIssued: (sale: Sale) => void;
  onClose: () => void;
}) {
  const client = useQueryClient();
  const { toast } = useToast();
  const [tenders, setTenders] = useState<TenderLine[]>(() => [
    { key: nextTenderKey(), method: 'cash', amount: '', tendered: '' },
  ]);
  const [notes, setNotes] = useState('');

  const total = cart.grand_total;
  const lines = cart.items ?? [];
  const summary = useMemo(() => summarise(total, tenders), [total, tenders]);

  const checkout = useMutation({
    mutationFn: () =>
      saleApi.checkout({
        cart_id: cart.id,
        notes: notes.trim() === '' ? null : notes.trim(),
        payments: paymentsPayload(tenders),
      }),
    onSuccess: (response) => {
      onIssued(response.data);
      // The cart is consumed by the sale, so the till must not keep showing it:
      // refetching re-opens an empty working cart for the next customer.
      client.invalidateQueries({ queryKey: CART_QUERY_KEY });
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Checkout failed',
        message: apiErrorMessage(error, 'Nothing was sold and nothing was deducted.'),
      });
    },
  });

  const patch = (key: string, change: Partial<TenderLine>) => {
    setTenders((current) =>
      current.map((tender) => (tender.key === key ? { ...tender, ...change } : tender))
    );
  };

  const addTender = () => {
    setTenders((current) => [
      ...current,
      { key: nextTenderKey(), method: 'cash', amount: summary.balance, tendered: '' },
    ]);
  };

  /** One keystroke from the common case: the customer hands over the exact bill. */
  const fillBalance = () => {
    setTenders((current) => {
      const row = { key: nextTenderKey(), method: 'cash' as const, amount: summary.balance, tendered: '' };

      return current.length === 0 ? [row] : [{ ...current[0]!, amount: summary.balance }, ...current.slice(1)];
    });
  };

  const canPay = summary.settled && !summary.over && !checkout.isPending;

  return (
    <Modal
      open
      onClose={checkout.isPending ? () => undefined : onClose}
      title="Take payment"
      description={`${lines.length} ${lines.length === 1 ? 'line' : 'lines'} · the total is the server's, recalculated when you press Pay.`}
      size="lg"
      footer={
        <div className="flex w-full flex-wrap items-center gap-2">
          <BalanceLine summary={summary} />

          <div className="ml-auto flex items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              onClick={fillBalance}
              disabled={summary.settled || summary.over}
            >
              Exact
            </Button>
            <Button
              variant="outline"
              size="sm"
              icon="add-outline"
              onClick={addTender}
              disabled={tenders.length >= 10}
            >
              Split
            </Button>

            {/* A ticket tendered short is a legitimate outcome — a card that will
                not read, a customer at the ATM — and it leaves a numbered,
                snapshotted sale in Draft or Partially Paid with nothing moved. */}
            {!summary.settled && !summary.over && (
              <Button
                variant="secondary"
                icon="document-outline"
                loading={checkout.isPending}
                onClick={() => checkout.mutate()}
              >
                {summary.empty
                  ? 'Issue unpaid'
                  : `Issue with ${formatMoneyString(summary.paid)} paid`}
              </Button>
            )}

            <Button
              variant="primary"
              icon="checkmark-circle-outline"
              loading={checkout.isPending}
              disabled={!canPay}
              onClick={() => checkout.mutate()}
            >
              Pay {formatMoneyString(summary.total)}
            </Button>
          </div>
        </div>
      }
    >
      <div className="flex flex-col gap-3">
        <div className="rounded-lg border border-border bg-surface-alt/40 px-3 py-2.5">
          <dl className="flex flex-col gap-1 text-sm">
            <Row label="Subtotal" value={formatMoneyString(cart.subtotal)} />
            {moneyUnits(cart.item_discount_total) > 0 && (
              <Row
                label="Item discounts"
                value={`- ${formatMoneyString(cart.item_discount_total)}`}
                tone="text-success"
              />
            )}
            {moneyUnits(cart.discount_total) > 0 && (
              <Row
                label="Cart discount"
                value={`- ${formatMoneyString(cart.discount_total)}`}
                tone="text-success"
              />
            )}
            {moneyUnits(cart.tax_total) > 0 && (
              <Row label="Tax" value={formatMoneyString(cart.tax_total)} />
            )}
            {moneyUnits(cart.other_charges) > 0 && (
              <Row label="Other charges" value={formatMoneyString(cart.other_charges)} />
            )}
            {moneyUnits(cart.rounding) !== 0 && (
              <Row label="Rounding" value={formatMoneyString(cart.rounding)} />
            )}
            <div className="mt-1 flex items-baseline justify-between border-t border-dashed border-border pt-2">
              <dt className="text-sm font-semibold text-text">Total due</dt>
              <dd className="font-mono text-xl font-semibold text-text">
                {formatMoneyString(total)}
              </dd>
            </div>
          </dl>
        </div>

        <div className="flex flex-col gap-2">
          {tenders.map((tender) => (
            <TenderRow
              key={tender.key}
              tender={tender}
              balance={summary.balance}
              onChange={(change) => patch(tender.key, change)}
              onRemove={() =>
                setTenders((current) => current.filter((entry) => entry.key !== tender.key))
              }
              removable={tenders.length > 1}
            />
          ))}
        </div>

        <Input
          name="sale_note"
          label="Note on the receipt (optional)"
          placeholder="e.g. receipt delivered by email"
          value={notes}
          maxLength={2000}
          onChange={(event) => setNotes(event.target.value)}
        />

        {summary.over && (
          <p className="text-xs text-danger">
            Paid {formatMoneyString(subMoney(summary.paid, summary.total))} more than the total.
            The server refuses an over-tendered payment, so Pay stays off until the figures match.
          </p>
        )}
      </div>
    </Modal>
  );
}

function Row({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-text-muted">{label}</dt>
      <dd className={cn('font-mono tabular-nums text-text', tone)}>{value}</dd>
    </div>
  );
}

/**
 * The one figure a cashier reads while typing: what is still owed, or what is
 * coming back. Both are stated, never carried by colour alone.
 */
function BalanceLine({ summary }: { summary: ReturnType<typeof summarise> }) {
  if (summary.over) {
    return (
      <span className="text-xs font-medium text-danger">
        Over by {formatMoneyString(subMoney(summary.paid, summary.total))}
      </span>
    );
  }

  if (summary.settled) {
    return moneyUnits(summary.change) > 0 ? (
      <span className="text-xs font-medium text-success">
        Change due <span className="font-mono text-sm">{formatMoneyString(summary.change)}</span>
      </span>
    ) : (
      <span className="text-xs font-medium text-success">Settled exactly</span>
    );
  }

  return (
    <span className={cn('text-xs', summary.empty ? 'text-text-subtle' : 'text-warning')}>
      {summary.empty ? 'Nothing entered yet' : 'Still owed'}{' '}
      {!summary.empty && (
        <span className="font-mono text-sm text-text">{formatMoneyString(summary.balance)}</span>
      )}
    </span>
  );
}

/**
 * The ticket exists; this screen hands it over.
 *
 * Change, print, and the status the sale actually landed in. A tendered-short
 * checkout is a real outcome — no money at all leaves a numbered order for
 * .../complete to finish — so it is reported rather than hidden behind a
 * success tick.
 */
function IssuedSale({ sale, onAnother }: { sale: Sale; onAnother: () => void }) {
  const { print, printing } = useReceiptPrinter(sale.id);
  const outstanding = moneyUnits(sale.balance_due) > 0;

  return (
    <Modal
      open
      onClose={onAnother}
      title={sale.number}
      description={`${sale.status_label} · ${sale.date}`}
      size="md"
      footer={
        <Button variant="primary" onClick={onAnother}>
          New sale
        </Button>
      }
    >
      <div className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-surface-alt/40 px-3 py-3">
          <div className="flex flex-col">
            <span className="text-[10px] tracking-wide text-text-subtle uppercase">Total</span>
            <span className="font-mono text-xl font-semibold text-text">
              {formatMoneyString(sale.grand_total)}
            </span>
          </div>
          <Badge variant={outstanding ? 'warning' : 'success'}>
            {outstanding ? `Owed ${formatMoneyString(sale.balance_due)}` : sale.payment_status}
          </Badge>
        </div>

        {moneyUnits(sale.change_due) > 0 && (
          <div className="flex items-baseline justify-between rounded-lg border border-success/30 bg-success-soft px-3 py-2.5">
            <span className="text-sm font-medium text-success">Give back</span>
            <span className="font-mono text-lg font-semibold text-success">
              {formatMoneyString(sale.change_due)}
            </span>
          </div>
        )}

        <div className="flex flex-col gap-1.5">
          <span className="text-[10px] tracking-wide text-text-subtle uppercase">Tenders</span>
          {sale.payments.length === 0 ? (
            <p className="text-xs text-warning">
              No payment taken — the ticket is waiting in the sales list to be completed.
            </p>
          ) : (
            sale.payments.map((payment) => (
              <div key={payment.id} className="flex items-baseline justify-between gap-2 text-sm">
                <span className="text-text-muted">
                  {payment.method_label}
                  <span className="ml-2 font-mono text-[11px] text-text-subtle">
                    {payment.number}
                  </span>
                </span>
                <span className="font-mono tabular-nums text-text">
                  {formatMoneyString(payment.amount)}
                </span>
              </div>
            ))
          )}
        </div>

        <PrintButtons printing={printing} onPrint={print} />
      </div>
    </Modal>
  );
}
