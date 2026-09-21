import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import type { RegisterSession } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { formatMoneyString } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';
import { Modal } from '@/components/ui/overlay';
import { EXPECTED_CASH_FORMULA, formatVariance, localDateTimeValue, previewCount } from './shift-model';
import { useCloseShift, useVarianceThreshold } from './use-shift';

/**
 * Close a register: count the drawer, and the difference decides who signs (Phase 3.4).
 *
 * The cashier supplies exactly one figure — Actual cash. Everything else on this
 * screen is the server's: expected cash is the running `summarise()` the till bar
 * already shows, so the number a cashier is being measured against is the one they
 * have been watching all shift, not one that appears at the count.
 *
 * The variance line updates as the count is typed, which is the useful direction
 * for a preview: it lets a cashier re-count before submitting rather than after
 * being asked why the drawer is short. Whether the difference actually needs a
 * supervisor is decided at close against the shop's threshold, and the answer comes
 * back on the shift as `requires_approval` — the tolerance read here is only to
 * warn, and a cashier without settings.view simply does not get the warning.
 */
export function CloseShiftDialog({
  open,
  shift,
  onClose,
}: {
  open: boolean;
  shift: RegisterSession | null;
  onClose: () => void;
}) {
  const can = useAuthStore((state) => state.can);
  const threshold = useVarianceThreshold(open && can('settings.view'));
  const closeShift = useCloseShift(() => onClose());

  const [actual, setActual] = useState('');
  const [closedAt, setClosedAt] = useState(() => localDateTimeValue());
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (open) {
      setActual('');
      setNotes('');
      setClosedAt(localDateTimeValue());
    }
  }, [open]);

  if (!shift) {
    return null;
  }

  const summary = shift.summary;
  // A settings value is `unknown` on the wire; anything that is not a number is
  // read as "no tolerance available", which is the same as not warning at all.
  const rawTolerance = threshold.data?.data?.value;
  const tolerance =
    can('settings.view') && (typeof rawTolerance === 'string' || typeof rawTolerance === 'number')
      ? String(rawTolerance)
      : null;
  const count = previewCount(summary?.expected_cash ?? null, actual, tolerance);
  const counted = actual.trim() !== '';

  const submit = () => {
    closeShift.mutate({
      id: shift.id,
      payload: {
        actual_balance: actual,
        closed_at: closedAt === '' ? undefined : new Date(closedAt).toISOString(),
        notes: notes.trim() === '' ? null : notes.trim(),
      },
    });
  };

  return (
    <Modal
      open={open}
      onClose={closeShift.isPending ? () => undefined : onClose}
      title={`Close ${shift.number}`}
      description={`${shift.register_code ?? 'Register'} · ${shift.cashier?.name ?? 'Cashier'} · ${summary?.sales_count ?? '0'} sale(s), ${summary?.movement_count ?? '0'} cash movement(s)`}
      size="md"
      footer={
        <div className="flex w-full flex-wrap items-center gap-2">
          <span className="text-xs text-text-muted">
            {summary?.unattributed_cash && Number(summary.unattributed_cash) !== 0
              ? `${formatMoneyString(summary.unattributed_cash)} of cash was taken outside this shift — see the report.`
              : 'Closing stops this drawer from taking more business.'}
          </span>
          <div className="ml-auto flex gap-2">
            <Button variant="secondary" size="sm" onClick={onClose} disabled={closeShift.isPending}>
              Not yet
            </Button>
            <Button
              variant="danger"
              size="sm"
              icon="lock-closed-outline"
              loading={closeShift.isPending}
              disabled={!counted || Number(actual) < 0}
              onClick={submit}
            >
              Close register
            </Button>
          </div>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-border bg-surface-alt p-3 text-sm">
          <Figure label="Opening cash" value={summary?.opening_balance} />
          <Figure label="Cash sales" value={summary?.cash_sales} />
          <Figure label="Cash in" value={summary?.cash_in} />
          <Figure label="Cash refunds" value={summary?.cash_refunds} negative />
          <Figure label="Cash out" value={summary?.cash_out} negative />
          <Figure label="Expected cash" value={summary?.expected_cash} emphasis hint={EXPECTED_CASH_FORMULA} />
        </dl>

        <Input
          type="number"
          inputMode="decimal"
          min="0"
          step="any"
          label="Actual cash counted"
          name="shift_actual_balance"
          autoComplete="off"
          placeholder="0"
          value={actual}
          onChange={(event) => setActual(event.target.value)}
          hint="Count the drawer and type what is physically in it, notes and coins."
          error={
            counted && Number(actual) < 0 ? 'A drawer cannot be counted as negative.' : undefined
          }
        />

        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3">
          <span className="text-xs tracking-wide text-text-subtle uppercase">Variance</span>
          <span
            className={
              'font-mono text-2xl font-semibold tabular-nums ' +
              (!counted
                ? 'text-text-subtle'
                : count.exact
                  ? 'text-success'
                  : count.short
                    ? 'text-danger'
                    : 'text-warning')
            }
          >
            {counted ? formatVariance(count.variance) : '—'}
          </span>

          {counted && !count.exact && (
            <Badge variant={count.short ? 'danger' : 'warning'} icon="alert-circle-outline">
              {count.short ? 'Short' : 'Long'} by{' '}
              {formatMoneyString(count.variance.replace(/^-/, ''))}
            </Badge>
          )}

          {counted && count.exact && (
            <Badge variant="success" icon="checkmark-circle">
              Drawer is even
            </Badge>
          )}

          {counted && count.needsApproval && tolerance !== null && (
            <Badge variant="warning" icon="person-check-outline">
              Over the {formatMoneyString(tolerance)} tolerance — needs a supervisor
            </Badge>
          )}

          <span className="ml-auto text-xs text-text-subtle">
            Actual − Expected
          </span>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <Input
            type="datetime-local"
            label="Closed at"
            name="shift_closed_at"
            value={closedAt}
            onChange={(event) => setClosedAt(event.target.value)}
          />
          <div className="flex items-end">
            <Link
              to={`/registers/shifts/${shift.id}`}
              className="text-xs text-primary hover:underline"
            >
              Open this shift's report
            </Link>
          </div>
        </div>

        <Textarea
          label="Closing note"
          name="shift_close_notes"
          placeholder="Anything the person reading this tomorrow needs to know."
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  );
}

function Figure({
  label,
  value,
  emphasis,
  negative,
  hint,
}: {
  label: string;
  value: string | null | undefined;
  emphasis?: boolean;
  negative?: boolean;
  hint?: string;
}) {
  return (
    <div className="flex flex-col" title={hint}>
      <dt className="text-[10px] tracking-wide text-text-subtle uppercase">{label}</dt>
      <dd
        className={
          'font-mono tabular-nums ' +
          (emphasis ? 'text-base font-semibold text-text' : 'text-sm text-text-muted')
        }
      >
        {negative && Number(value) > 0 ? `−${formatMoneyString(value)}` : formatMoneyString(value)}
      </dd>
    </div>
  );
}
