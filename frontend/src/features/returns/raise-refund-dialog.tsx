import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { RefundMethod, Sale } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { formatMoneyString, labelFor } from '@/utils/format';
import { listQueryKeys } from '@/lib/query-client';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import { moneyUnits, subMoney } from '@/features/sales/money-math';

/** The three ways a person may hand money back today; gateway is server-driven. */
const METHOD_OPTIONS: Array<{ value: RefundMethod; label: string }> = [
  { value: 'cash', label: labelFor.refundMethod('cash') },
  { value: 'original_payment', label: labelFor.refundMethod('original_payment') },
  { value: 'manual', label: labelFor.refundMethod('manual') },
];

/**
 * Raise a refund against a sale (Phase 3.5).
 *
 * The form takes the amount, how it goes back and why — never which tenders it
 * comes off: the engine allocates oldest-first unless the caller names them, and
 * a screen that split a card and a cash tender by hand would be a second opinion
 * nobody asked for. Whether the refund needs a manager depends on the shop's
 * threshold, which is the server's call; the dialog only reports what came back.
 */
export function RaiseRefundDialog({
  open,
  onClose,
  sale,
  saleReturnId,
}: {
  open: boolean;
  onClose: () => void;
  sale: Sale;
  saleReturnId?: number | null;
}) {
  const client = useQueryClient();
  const { toast } = useToast();

  const remaining = subMoney(sale.refundable_amount, sale.refunded_total);

  const [amount, setAmount] = useState(remaining);
  const [method, setMethod] = useState<RefundMethod>('original_payment');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  const mutation = useMutation({
    mutationFn: () =>
      saleApi.raiseRefund(sale.id, {
        amount,
        method,
        reason: reason.trim(),
        notes: notes.trim() === '' ? null : notes.trim(),
        sale_return_id: saleReturnId ?? null,
      }),
    onSuccess: (response) => {
      client.invalidateQueries({ queryKey: ['sales', sale.id, 'detail'] });
      client.invalidateQueries({ queryKey: listQueryKeys.sales });
      client.invalidateQueries({ queryKey: listQueryKeys.refunds });
      client.invalidateQueries({ queryKey: ['register-sessions'] });

      const refund = response.data;
      toast({
        variant: 'success',
        title: `Refund ${refund.number} raised`,
        message: refund.approval_required
          ? 'It is at or over the approval threshold, so it waits for a manager.'
          : 'It was approved by the shop rule and can be paid out.',
      });

      setAmount('');
      setReason('');
      setNotes('');
      onClose();
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Could not raise the refund',
        message: apiErrorMessage(error, 'Nothing was refunded.'),
      });
    },
  });

  const overRefundable = moneyUnits(amount) > moneyUnits(remaining);
  const invalid = moneyUnits(amount) <= 0 || overRefundable || reason.trim() === '';

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Raise a refund"
      description={`${sale.number} · up to ${formatMoneyString(remaining)} still refundable`}
      size="md"
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            icon="cash-outline"
            loading={mutation.isPending}
            disabled={invalid}
            onClick={() => mutation.mutate()}
          >
            Raise refund
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <Input
          name="refund_amount"
          label="Amount"
          inputMode="decimal"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          error={overRefundable ? `Only ${formatMoneyString(remaining)} is still refundable.` : undefined}
          hint={
            overRefundable
              ? undefined
              : `Already refunded ${formatMoneyString(sale.refunded_total)} of ${formatMoneyString(sale.refundable_amount)}.`
          }
          autoFocus
        />

        <Select
          name="refund_method"
          label="How the money goes back"
          options={METHOD_OPTIONS}
          value={method}
          onChange={(event) => setMethod(event.target.value as RefundMethod)}
        />

        <Textarea
          name="refund_reason"
          label="Reason"
          placeholder="e.g. the customer returned a damaged item"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />

        <Input
          name="refund_notes"
          label="Notes (optional)"
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  );
}