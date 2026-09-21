import { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { Sale } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { formatMoneyString } from '@/utils/format';
import { useToast } from '@/components/ui/toast';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { listQueryKeys } from '@/lib/query-client';
import { moneyUnits } from './money-math';
import {
  defaultTenderMethod,
  newTender,
  paymentsPayload,
  summarise,
  applyTenderPatch,
  tenderProblems,
  withDefaultMethods,
  availableMethods,
  type TenderLine,
} from './tender-model';
import { useAvailablePaymentMethods } from '@/features/payments/use-payment-methods';
import { TenderRow } from './tenders';

/**
 * Settle a ticket that left the counter unpaid (Phase 3.2).
 *
 * The other half of the flow: a checkout tendered short — no money at all, a
 * card that would not read, a customer walking to the ATM — is a numbered sale
 * sitting in Draft or Partially Paid with nothing moved. This posts what is owed
 * against it through /sales/{id}/complete and the server decides the outcome:
 * still short means the status updates and the stock stays on the shelf, settled
 * means the stock movement is written in the same transaction.
 *
 * The form opens on the balance rather than on zero because that is the number a
 * cashier is chasing here, with the total beside it so a part-payment is never
 * mistaken for the whole ticket.
 */
export function SettleDialog({
  open,
  onClose,
  sale,
}: {
  open: boolean;
  onClose: () => void;
  sale: Sale;
}) {
  if (!open) {
    return null;
  }

  // Keyed on the sale so a ticket's half-typed tenders cannot appear on the
  // next one opened from the same page.
  return <SettleForm key={sale.id} sale={sale} onClose={onClose} />;
}

function SettleForm({ sale, onClose }: { sale: Sale; onClose: () => void }) {
  const client = useQueryClient();
  const { toast } = useToast();
  const owed = sale.balance_due;
  const methods = useAvailablePaymentMethods();
  const methodList = availableMethods(methods.data?.data);

  // Opens on the balance, because that is the figure being chased here; the method
  // is the shop's default once the list has arrived.
  const [tenders, setTenders] = useState<TenderLine[]>(() => [newTender(null, owed)]);

  const rows = useMemo(() => withDefaultMethods(tenders, methodList), [tenders, methodList]);
  const summary = useMemo(() => summarise(owed, rows), [owed, rows]);
  const problems = tenderProblems(rows);

  const settle = useMutation({
    mutationFn: () => saleApi.complete(sale.id, paymentsPayload(rows)),
    onSuccess: (response) => {
      const settled = response.data.fully_paid;

      client.invalidateQueries({ queryKey: ['sales', response.data.id, 'detail'] });
      client.invalidateQueries({ queryKey: listQueryKeys.sales });
      // A tender taken after the sale is still cash into the drawer it belongs to,
      // so the shift's expected figure has to be re-read with it.
      client.invalidateQueries({ queryKey: ['register-sessions'] });

      toast({
        variant: settled ? 'success' : 'warning',
        title: settled ? `Sale ${response.data.number} completed` : 'Part-payment recorded',
        message: settled
          ? 'Stock has been deducted and the invoice is closed.'
          : `${formatMoneyString(response.data.balance_due)} is still owed.`,
      });

      if (settled) {
        onClose();
      }
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Payment refused',
        message: apiErrorMessage(error, 'Nothing was recorded against this sale.'),
      });
    },
  });

  const patch = (key: string, change: Partial<TenderLine>) => {
    setTenders((current) =>
      current.map((tender) => (tender.key === key ? applyTenderPatch(tender, change) : tender))
    );
  };

  const addRow = () => {
    setTenders((current) => [
      ...current,
      newTender(current[0]?.method ?? defaultTenderMethod(methodList), summary.balance),
    ]);
  };

  return (
    <Modal
      open
      onClose={settle.isPending ? () => undefined : onClose}
      title={`Take the balance on ${sale.number}`}
      description={`${formatMoneyString(sale.grand_total)} total · ${formatMoneyString(sale.paid_total)} paid so far.`}
      size="md"
      footer={
        <div className="flex w-full flex-wrap items-center gap-2">
          <span className="text-xs text-text-muted">
            {problems.length > 0
              ? problems[0]
              : summary.over
                ? 'More than the balance.'
                : summary.settled
                  ? 'Settled — completing this posts the stock too.'
                  : `${formatMoneyString(summary.balance)} still owed`}
          </span>
          <div className="ml-auto flex gap-2">
            <Button variant="secondary" size="sm" onClick={onClose} disabled={settle.isPending}>
              Later
            </Button>
            <Button
              variant="primary"
              size="sm"
              icon="cash-outline"
              loading={settle.isPending}
              disabled={summary.empty || summary.over || problems.length > 0}
              onClick={() => settle.mutate()}
            >
              Record payment
            </Button>
          </div>
        </div>
      }
    >
      <div className="flex flex-col gap-2">
        {rows.map((tender) => (
          <TenderRow
            key={tender.key}
            tender={tender}
            methods={methodList}
            balance={summary.balance}
            onChange={(change) => patch(tender.key, change)}
            onRemove={() =>
              setTenders((current) => current.filter((entry) => entry.key !== tender.key))
            }
            removable={tenders.length > 1}
          />
        ))}

        <Button
          variant="ghost"
          size="sm"
          icon="add-outline"
          onClick={addRow}
          disabled={tenders.length >= 10 || moneyUnits(summary.balance) <= 0}
        >
          Another payment method
        </Button>
      </div>
    </Modal>
  );
}
