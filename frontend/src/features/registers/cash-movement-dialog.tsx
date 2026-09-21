import { useEffect, useState } from 'react';
import type { CashMovementType, RegisterSession } from '@/types';
import { addMoney, subMoney } from '@/features/sales/money-math';
import { formatMoneyString } from '@/utils/format';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Modal } from '@/components/ui/overlay';
import { localDateTimeValue, movementOptionsFor } from './shift-model';
import { useCashMovement } from './use-shift';

/**
 * Cash in / cash out on an open shift (Phase 3.4).
 *
 * One dialog for both sides, because the form is the same and the distinction that
 * matters is not the direction but the reason: the six are the backend's vocabulary
 * (Cash In: injection, other income; Cash Out: expense, withdrawal, petty cash, and
 * a cash refund not recorded on a ticket), and a form that offered a reason from the
 * wrong side would be asking for a movement the report then counts backwards. So the
 * side picks the list rather than picking the sign.
 *
 * Reason is required and stays on the record — it is the only thing that will
 * explain the money to whoever reads this shift next month.
 */
export function CashMovementDialog({
  open,
  side,
  shift,
  onClose,
}: {
  open: boolean;
  side: 'in' | 'out';
  shift: RegisterSession | null;
  onClose: () => void;
}) {
  const record = useCashMovement(shift?.id ?? null);
  const options = movementOptionsFor(side);
  const fallbackType = options[0]?.value ?? 'expense';

  const [type, setType] = useState<CashMovementType>(fallbackType);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [reference, setReference] = useState('');
  const [occurredAt, setOccurredAt] = useState(() => localDateTimeValue());
  const [notes, setNotes] = useState('');

  // Switching between Cash in and Cash out mid-press must not leave the previous
  // side's reason attached to the new one.
  useEffect(() => {
    if (open) {
      setType(movementOptionsFor(side)[0]?.value ?? fallbackType);
      setAmount('');
      setReason('');
      setReference('');
      setNotes('');
      setOccurredAt(localDateTimeValue());
    }
  }, [open, side]);

  const chosen = options.find((option) => option.value === type);
  const value = amount.trim() === '' ? null : amount;
  const ready = value !== null && Number(value) > 0 && reason.trim() !== '';

  const submit = () => {
    record.mutate({
      type,
      amount: value ?? '0',
      reason: reason.trim(),
      reference: reference.trim() === '' ? null : reference.trim(),
      occurred_at: occurredAt === '' ? undefined : new Date(occurredAt).toISOString(),
      notes: notes.trim() === '' ? null : notes.trim(),
    });
  };

  const expected = shift?.summary?.expected_cash ?? null;
  // A preview only, and one the cashier can ignore: the server recomputes expected
  // cash from the ledger the moment the movement is written.
  const projected =
    expected !== null && value !== null && Number(value) > 0
      ? side === 'in'
        ? addMoney(expected, value)
        : subMoney(expected, value)
      : null;

  return (
    <Modal
      open={open}
      onClose={record.isPending ? () => undefined : onClose}
      title={side === 'in' ? 'Cash in' : 'Cash out'}
      description={
        side === 'in'
          ? `Money put into ${shift?.number ?? 'the drawer'} that is not a sale.`
          : `Money taken out of ${shift?.number ?? 'the drawer'} that is not change.`
      }
      size="md"
      footer={
        <div className="flex w-full flex-wrap items-center gap-2">
          <span className="text-xs text-text-muted">
            {projected
              ? `Expected cash ${formatMoneyString(expected)} → ${formatMoneyString(projected)}`
              : 'This moves the drawer, and the shift report with it.'}
          </span>
          <div className="ml-auto flex gap-2">
            <Button variant="secondary" size="sm" onClick={onClose} disabled={record.isPending}>
              Cancel
            </Button>
            <Button
              variant={side === 'in' ? 'primary' : 'danger'}
              size="sm"
              icon={side === 'in' ? 'add-circle-outline' : 'remove-circle-outline'}
              loading={record.isPending}
              disabled={!ready || !shift}
              onClick={submit}
            >
              Record {side === 'in' ? 'cash in' : 'cash out'}
            </Button>
          </div>
        </div>
      }
    >
      <div className="flex flex-col gap-3">
        <Select
          label="Reason"
          name="movement_type"
          options={options.map((option) => ({ label: option.label, value: option.value }))}
          value={type}
          onChange={(event) => setType(event.target.value as CashMovementType)}
        />
        {chosen && <p className="-mt-2 text-xs text-text-subtle">{chosen.hint}</p>}

        <Input
          type="number"
          inputMode="decimal"
          min="0"
          step="any"
          label="Amount"
          name="movement_amount"
          autoComplete="off"
          placeholder="0"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          error={
            value !== null && Number(value) <= 0
              ? 'Money that did not move is not a movement.'
              : undefined
          }
        />

        <Textarea
          label="Reason (what happened)"
          name="movement_reason"
          placeholder="Paid the courier for the afternoon delivery…"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />

        <Input
          label="Reference"
          name="movement_reference"
          placeholder="Receipt number, voucher, bank slip"
          autoComplete="off"
          value={reference}
          onChange={(event) => setReference(event.target.value)}
        />

        <Input
          type="datetime-local"
          label="When"
          name="movement_occurred_at"
          value={occurredAt}
          onChange={(event) => setOccurredAt(event.target.value)}
        />

        <Textarea
          label="Note"
          name="movement_notes"
          placeholder="Anything that will make this readable later."
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  );
}
