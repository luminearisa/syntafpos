import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { Sale, SaleItem, SalePayment } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { useAuthStore } from '@/stores/auth-store';
import { listQueryKeys } from '@/lib/query-client';
import { cn, formatDecimal, formatDate, formatMoneyString, labelFor } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/overlay';
import { ErrorState, LoadingState, PageHeader } from '@/components/ui/state';
import { useToast } from '@/components/ui/toast';
import { moneyUnits } from './money-math';
import { PrintButtons } from './print-buttons';
import { useReceiptPrinter } from './print';
import { SaleStatusBadge } from './sale-status-badge';
import { SettleDialog } from './settle-dialog';

/**
 * The invoice itself (Phase 3.2).
 *
 * This is the document the work order asks for: company, outlet, customer,
 * invoice number, date, lines, discount, tax, total and payment status — every
 * figure read off the sale's own snapshot columns, so nothing on this page can
 * change because a product was later renamed or repriced.
 *
 * What a posted sale allows is narrow by design: take the rest of the money,
 * cancel it, print it. There is no edit and no delete, and the buttons reflect
 * that rather than offering a greyed-out version of one.
 */
export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const saleId = Number(id);
  const navigate = useNavigate();
  const client = useQueryClient();
  const { toast } = useToast();
  const can = useAuthStore((state) => state.can);
  const [settling, setSettling] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');

  const query = useQuery({
    queryKey: ['sales', saleId, 'detail'],
    queryFn: () => saleApi.show(saleId),
    enabled: Number.isInteger(saleId) && saleId > 0,
  });

  const sale = query.data?.data ?? null;

  const { print, printing } = useReceiptPrinter(saleId);

  const cancel = useMutation({
    mutationFn: () => saleApi.cancel(saleId, reason.trim() === '' ? null : reason.trim()),
    onSuccess: (response) => {
      client.invalidateQueries({ queryKey: ['sales', saleId, 'detail'] });
      client.invalidateQueries({ queryKey: listQueryKeys.sales });
      setCancelling(false);
      setReason('');
      toast({
        variant: 'success',
        title: `Sale ${response.data.number} cancelled`,
        message: 'Stock has been returned through the ledger and the tenders are voided.',
      });
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Cannot cancel this sale',
        message: apiErrorMessage(error, 'The sale was left as it was.'),
      });
    },
  });

  if (query.isPending) {
    return <LoadingState label="Loading invoice..." className="py-24" />;
  }

  if (query.isError || !sale) {
    return (
      <ErrorState
        message={apiErrorMessage(query.error, 'That invoice could not be loaded.')}
        onRetry={() => query.refetch()}
      />
    );
  }

  const open = sale.status !== 'cancelled' && !sale.fully_paid;
  const mayCancel = can('sales.cancel') && sale.status !== 'cancelled';
  const maySettle = can('sales.complete') && open;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={sale.number}
        description={`${sale.outlet.name ?? 'Outlet'} · ${formatDate(sale.date, true)} · ${sale.customer.name ?? 'Walk-in'}`}
        actions={
          <>
            <PrintButtons printing={printing} onPrint={print} />
            {maySettle && (
              <Button variant="primary" size="sm" icon="cash-outline" onClick={() => setSettling(true)}>
                Take payment
              </Button>
            )}
            {mayCancel && (
              <Button variant="outline" size="sm" icon="close-circle-outline" onClick={() => setCancelling(true)}>
                Cancel sale
              </Button>
            )}
            <Button variant="ghost" size="sm" icon="arrow-back-outline" onClick={() => navigate('/sales')}>
              Back
            </Button>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <SaleStatusBadge status={sale.status} />
        <Badge variant={sale.fully_paid ? 'success' : 'warning'}>{sale.payment_status}</Badge>
        {sale.cancelled_at && (
          <span className="text-xs text-danger">
            Cancelled {formatDate(sale.cancelled_at, true)}
            {sale.cancel_reason ? ` — ${sale.cancel_reason}` : ''}
          </span>
        )}
        {sale.stock_posted_at && (
          <span className="text-xs text-text-subtle">
            Stock deducted {formatDate(sale.stock_posted_at, true)}
          </span>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader
            title="Lines"
            description="Snapshot text taken at checkout — it does not follow the catalogue."
          />
          <CardBody className="overflow-x-auto p-0">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-border bg-surface-alt text-xs text-text-muted">
                  <th scope="col" className="px-3 py-2 text-left font-semibold">Product</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Qty</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Price</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Disc.</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Tax</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Total</th>
                </tr>
              </thead>
              <tbody>
                {(sale.items ?? []).map((item) => (
                  <SaleItemRow key={item.id} item={item} />
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader title="Totals" />
            <CardBody className="flex flex-col gap-1.5 text-sm">
              <MoneyLine label="Subtotal" value={sale.subtotal} />
              {moneyUnits(sale.item_discount_total) > 0 && (
                <MoneyLine label="Item discounts" value={sale.item_discount_total} negative />
              )}
              {moneyUnits(sale.discount_total) > 0 && (
                <MoneyLine
                  label={`Cart discount${discountNote(sale)}`}
                  value={sale.discount_total}
                  negative
                />
              )}
              {moneyUnits(sale.tax_total) > 0 && (
                <MoneyLine
                  label={
                    moneyUnits(sale.tax_included_total) > 0
                      ? `Tax (incl. ${formatMoneyString(sale.tax_included_total)})`
                      : 'Tax'
                  }
                  value={sale.tax_total}
                />
              )}
              {moneyUnits(sale.other_charges) > 0 && (
                <MoneyLine label="Other charges" value={sale.other_charges} />
              )}
              {moneyUnits(sale.rounding) !== 0 && (
                <MoneyLine label="Rounding" value={sale.rounding} />
              )}

              <div className="mt-1 flex items-baseline justify-between border-t border-dashed border-border pt-2">
                <span className="text-sm font-semibold text-text">Grand total</span>
                <span className="font-mono text-lg font-semibold text-text">
                  {formatMoneyString(sale.grand_total)}
                </span>
              </div>

              <MoneyLine label="Paid" value={sale.paid_total} />
              <div className="flex items-baseline justify-between">
                <span className="text-text-muted">Balance</span>
                <span
                  className={
                    moneyUnits(sale.balance_due) > 0
                      ? 'font-mono font-medium text-warning'
                      : 'font-mono text-text-muted'
                  }
                >
                  {formatMoneyString(sale.balance_due)}
                </span>
              </div>
              {moneyUnits(sale.change_due) > 0 && (
                <MoneyLine label="Change given" value={sale.change_due} />
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Payments" description={`${sale.payments.length} tender(s)`} />
            <CardBody className="flex flex-col gap-2 text-sm">
              {sale.payments.length === 0 ? (
                <p className="text-xs text-text-muted">
                  No payment has been recorded. {maySettle ? 'Take payment to close it.' : ''}
                </p>
              ) : (
                sale.payments.map((payment) => (
                  <PaymentRow key={payment.id} payment={payment} />
                ))
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader title="Customer" />
          <CardBody className="flex flex-col gap-1 text-sm">
            <span className="font-medium text-text">{sale.customer.name ?? 'Walk-in'}</span>
            {sale.customer.code && (
              <span className="font-mono text-xs text-text-subtle">{sale.customer.code}</span>
            )}
            {sale.customer.phone && <span className="text-xs text-text-muted">{sale.customer.phone}</span>}
            {sale.customer.address && (
              <span className="text-xs text-text-muted">{sale.customer.address}</span>
            )}
            {sale.customer.id && (
              <Link
                to="/customers"
                className="mt-1 text-xs text-primary hover:underline"
              >
                Customer record
              </Link>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Outlet & register" />
          <CardBody className="flex flex-col gap-1 text-sm">
            <span className="font-medium text-text">{sale.outlet.name ?? '-'}</span>
            {sale.outlet.address && (
              <span className="text-xs text-text-muted">{sale.outlet.address}</span>
            )}
            {sale.outlet.phone && <span className="text-xs text-text-muted">{sale.outlet.phone}</span>}
            <span className="mt-1 text-xs text-text-muted">
              {sale.register ? `${sale.register.code} — ${sale.register.name}` : 'No register'}
            </span>
            <span className="text-xs text-text-muted">
              {sale.warehouse ? sale.warehouse.name : 'No warehouse'} · {sale.cashier?.name ?? '-'}
            </span>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Company" />
          <CardBody className="flex flex-col gap-1 text-sm">
            <span className="font-medium text-text">{sale.company?.legal_name ?? sale.company?.name ?? '-'}</span>
            <span className="font-mono text-xs text-text-subtle">{sale.company?.code ?? '-'}</span>
            <span className="text-xs text-text-muted">
              Currency {sale.currency} · {sale.notes ? 'Note on the sale' : 'No note'}
            </span>
            {sale.notes && <p className="text-xs text-text-muted">{sale.notes}</p>}
          </CardBody>
        </Card>
      </div>

      <SettleDialog open={settling} onClose={() => setSettling(false)} sale={sale} />

      <ConfirmModal
        open={cancelling}
        onClose={() => setCancelling(false)}
        onConfirm={() => cancel.mutate()}
        loading={cancel.isPending}
        title="Cancel this sale?"
        confirmLabel="Cancel sale"
        message={
          <div className="flex flex-col gap-2">
            <p>
              {sale.number} will be withdrawn. Stock already deducted goes back through the ledger
              and recorded tenders are voided — cash is not refunded here, that is a returns flow.
            </p>
            <Input
              name="cancel_reason"
              label="Reason (optional)"
              placeholder="e.g. customer changed their mind"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </div>
        }
      />
    </div>
  );
}

function SaleItemRow({ item }: { item: SaleItem }) {
  return (
    <tr className="border-b border-border last:border-0">
      <td className="px-3 py-2">
        <div className="flex flex-col">
          <span className="font-medium text-text">{item.product_name}</span>
          <span className="font-mono text-[11px] text-text-subtle">
            {item.product_sku}
            {item.variant_name ? ` · ${item.variant_name}` : ''}
            {item.unit_code ? ` / ${item.unit_code}` : ''}
          </span>
          {item.notes && <span className="text-[11px] text-text-muted">{item.notes}</span>}
        </div>
      </td>
      <td className="px-3 py-2 text-right font-mono tabular-nums text-text">
        {formatDecimal(item.quantity, 6)}
      </td>
      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
        {formatMoneyString(item.unit_price)}
      </td>
      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
        {moneyUnits(item.discount_amount) > 0 ? formatMoneyString(item.discount_amount) : '-'}
      </td>
      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
        {formatMoneyString(item.tax_amount)}
        <span className="block text-[10px] text-text-subtle">
          {formatDecimal(item.tax_rate, 2)}% {item.tax_mode === 'inclusive' ? 'incl.' : 'excl.'}
        </span>
      </td>
      <td className="px-3 py-2 text-right font-mono tabular-nums text-text">
        {formatMoneyString(item.line_total)}
      </td>
    </tr>
  );
}

/**
 * One tender, as the ledger recorded it.
 *
 * The method name printed here is the snapshot on the payment row, not a lookup of
 * the shop's current configuration: a shop that renames "QRIS (launch promo)" to
 * "QRIS" must not rewrite what a past receipt said.
 *
 * A tender that settled nothing is shown but struck through and labelled, rather
 * than hidden — a declined card is part of why the ticket is still open.
 */
function PaymentRow({ payment }: { payment: SalePayment }) {
  const settled = payment.status === 'paid' || payment.status === 'partially_refunded';
  const inert = payment.status === 'cancelled' || payment.status === 'failed';
  const refunded = moneyUnits(payment.refunded_amount) > 0;

  return (
    <div className="flex items-baseline justify-between gap-2">
      <div className="flex min-w-0 flex-col">
        <span className={inert ? 'text-text-subtle line-through' : 'text-text'}>
          {payment.method_name || payment.channel_label || labelFor.paymentChannel(payment.channel)}
        </span>
        <span className="font-mono text-[11px] text-text-subtle">
          {payment.number}
          {payment.reference && <span className="ml-2 text-text-muted">{payment.reference}</span>}
        </span>
        {payment.paid_at && (
          <span className="text-[11px] text-text-subtle">{formatDate(payment.paid_at)}</span>
        )}
      </div>
      <div className="flex shrink-0 flex-col items-end">
        <span
          className={cn(
            'font-mono tabular-nums',
            inert ? 'text-text-subtle line-through' : 'text-text'
          )}
        >
          {formatMoneyString(payment.amount)}
        </span>
        {moneyUnits(payment.change) > 0 && (
          <span className="font-mono text-[11px] text-text-subtle">
            change {formatMoneyString(payment.change)}
          </span>
        )}
        {refunded && (
          <span className="font-mono text-[11px] text-warning">
            refunded {formatMoneyString(payment.refunded_amount)} · holding{' '}
            {formatMoneyString(payment.net_amount)}
          </span>
        )}
        {!settled && !inert && (
          <span className="text-[10px] text-warning">{payment.status_label}</span>
        )}
        {inert && <span className="text-[10px] text-danger">{payment.status_label}</span>}
      </div>
    </div>
  );
}

function MoneyLine({ label, value, negative = false }: { label: string; value: string; negative?: boolean }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <span className="text-text-muted">{label}</span>
      <span className={cn('font-mono tabular-nums', negative ? 'text-success' : 'text-text')}>
        {negative ? `- ${formatMoneyString(value)}` : formatMoneyString(value)}
      </span>
    </div>
  );
}

function discountNote(sale: Sale): string {
  if (sale.discount_type === 'percent') {
    return ` (${formatDecimal(sale.discount_input, 2)}%)`;
  }

  return '';
}
