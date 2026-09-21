import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import type { RegisterSession } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { apiErrorMessage } from '@/utils/api-error';
import { formatDate, formatMoneyString } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { Input, Textarea } from '@/components/ui/input';
import { Modal } from '@/components/ui/overlay';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/components/ui/state';
import { EXPECTED_CASH_FORMULA, formatDuration, formatVariance, movementOption } from './shift-model';
import {
  useApproveVariance,
  useReopenShift,
  useShift,
  useShiftMovements,
  useShiftReport,
} from './use-shift';

/**
 * One shift: its report, its cash movements, and the two manager actions (Phase 3.4).
 *
 * This is the screen the closing report was written for. The figures come from
 * `/report`, which is the same eight lines in the same order the close used, so a
 * manager comparing a count to a till display is reading one arithmetic twice rather
 * than two implementations of it.
 *
 * Approval and reopen sit here rather than on the list because they are decisions
 * made after reading the detail: approving signs a difference and never changes it,
 * and reopening puts a shut drawer back to work with a reason attached to it.
 */
export default function ShiftDetailPage() {
  const { id } = useParams();
  const shiftId = Number(id);
  const can = useAuthStore((state) => state.can);

  const [dialog, setDialog] = useState<'approve' | 'reopen' | null>(null);
  const valid = Number.isInteger(shiftId) && shiftId > 0;

  const shift = useShift(valid ? shiftId : null);
  const report = useShiftReport(valid ? shiftId : null);
  const movements = useShiftMovements(valid ? shiftId : null, { per_page: 100 });

  if (!valid) {
    return <ErrorState message="That is not a shift." />;
  }

  if (shift.isLoading) {
    return <LoadingState label="Reading the shift..." className="py-24" />;
  }

  if (shift.isError || !shift.data) {
    return (
      <ErrorState
        message="This shift could not be read. It may belong to another company."
        onRetry={() => shift.refetch()}
      />
    );
  }

  const row = shift.data.data;
  const summary = row.summary;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={row.number}
        description={`${row.register_code ?? 'Register'} · ${row.branch_name ?? 'This branch'} · ${row.cashier?.name ?? 'Cashier'}`}
        actions={
          <>
            <Link to="/registers/shifts" className="text-xs text-primary hover:underline">
              All shifts
            </Link>
            {row.awaiting_approval && can('register_sessions.approve') && (
              <Button size="sm" icon="checkmark-done-outline" onClick={() => setDialog('approve')}>
                Approve variance
              </Button>
            )}
            {row.status === 'closed' && can('register_sessions.reopen') && (
              <Button size="sm" variant="outline" icon="refresh-outline" onClick={() => setDialog('reopen')}>
                Reopen shift
              </Button>
            )}
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        {row.status === 'open' ? (
          <Badge variant="success" icon="radio-button-on-outline">
            Open · {formatDuration(row.duration_minutes)}
          </Badge>
        ) : (
          <Badge variant="outline" icon="lock-closed-outline">
            Closed {formatDate(row.closed_at, true)}
          </Badge>
        )}

        {row.awaiting_approval && (
          <Badge variant="warning" icon="person-check-outline">
            Variance awaiting approval
          </Badge>
        )}

        {row.is_approved && (
          <Badge variant="primary" icon="checkmark-done-outline">
            Approved by {row.approved_by ?? 'a supervisor'} · {formatDate(row.approved_at, true)}
          </Badge>
        )}

        {row.reopen_count > 0 && (
          <Badge variant="danger" icon="refresh-outline">
            Reopened {row.reopen_count}× by {row.reopened_by ?? 'a supervisor'}
          </Badge>
        )}

        {summary && Number(summary.unattributed_count) > 0 && (
          <Badge variant="info" icon="help-circle-outline">
            {formatMoneyString(summary.unattributed_cash)} of cash taken outside any shift
          </Badge>
        )}
      </div>

      {row.reopen_reason && (
        <p className="rounded-lg border border-border bg-surface-alt px-3 py-2 text-xs text-text-muted">
          <span className="font-medium text-text">Reopen reason: </span>
          {row.reopen_reason}
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Closing report"
            description={
              row.status === 'open'
                ? 'Running figures — expected cash is recomputed on every read.'
                : EXPECTED_CASH_FORMULA
            }
          />
          <CardBody className="p-0">
            {report.isLoading ? (
              <LoadingState label="Building the report..." className="py-10" />
            ) : (
              <dl className="divide-y divide-border">
                {(report.data?.data.report ?? []).map((line) => (
                  <div
                    key={line.key}
                    className="flex items-baseline justify-between gap-3 px-4 py-2"
                  >
                    <dt
                      className={
                        'text-sm ' +
                        (line.emphasis ? 'font-medium text-text' : 'text-text-muted')
                      }
                    >
                      {line.label}
                    </dt>
                    <dd
                      className={
                        'font-mono tabular-nums ' +
                        (line.emphasis ? 'text-base font-semibold text-text' : 'text-sm text-text')
                      }
                    >
                      {line.key === 'variance'
                        ? formatVariance(line.value)
                        : line.value === null
                          ? '—'
                          : formatMoneyString(line.value)}
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Cash movements"
            description="Money that went in and out of this drawer without being a tender."
            action={
              <span className="text-xs text-text-subtle">
                {summary?.movement_count ? `${summary.movement_count} recorded` : ''}
              </span>
            }
          />
          <CardBody className="max-h-[26rem] overflow-y-auto p-0">
            {movements.isLoading ? (
              <LoadingState label="Reading the drawer..." className="py-10" />
            ) : (movements.data?.data ?? []).length === 0 ? (
              <EmptyState
                title="No cash movements"
                description="Nothing was put into or taken out of this drawer outside the till."
              />
            ) : (
              <ul className="divide-y divide-border">
                {(movements.data?.data ?? []).map((movement) => (
                  <li key={movement.id} className="flex items-start gap-3 px-4 py-2.5">
                    <span
                      className={
                        'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ' +
                        (movement.group === 'in'
                          ? 'bg-success-soft text-success'
                          : movement.group === 'refund'
                            ? 'bg-warning-soft text-warning'
                            : 'bg-danger-soft text-danger')
                      }
                    >
                      <ion-icon
                        name={movementOption(movement.type)?.icon ?? 'cash-outline'}
                        class="text-sm"
                        aria-hidden="true"
                      />
                    </span>

                    <div className="min-w-0 flex-1">
                      <p className="text-sm text-text">{movement.reason}</p>
                      <p className="text-[11px] text-text-subtle">
                        {movement.type_label}
                        {movement.reference && ` · ${movement.reference}`}
                        {` · ${movement.user?.name ?? 'someone'} · ${formatDate(movement.occurred_at, true)}`}
                      </p>
                    </div>

                    <span
                      className={
                        'shrink-0 font-mono text-sm tabular-nums ' +
                        (movement.group === 'in' ? 'text-success' : 'text-danger')
                      }
                    >
                      {movement.signed_amount.startsWith('-')
                        ? `−${formatMoneyString(movement.signed_amount.slice(1))}`
                        : `+${formatMoneyString(movement.signed_amount)}`}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader title="Who did what" />
        <CardBody>
          <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <Detail label="Opened" value={row.opened_by ?? '—'} sub={formatDate(row.opened_at, true)} />
            <Detail label="Cashier" value={row.cashier?.name ?? '—'} sub={`Float ${formatMoneyString(row.opening_balance)}`} />
            <Detail
              label="Counted by"
              value={row.closed_by ?? (row.status === 'open' ? 'not yet' : '—')}
              sub={
                row.actual_balance === null
                  ? 'drawer uncounted'
                  : `${formatMoneyString(row.actual_balance)} counted`
              }
            />
            <Detail
              label="Approved by"
              value={row.approved_by ?? (row.requires_approval ? 'pending' : 'not needed')}
              sub={
                row.variance_threshold === null
                  ? formatVariance(row.variance)
                  : `tolerance ${formatMoneyString(row.variance_threshold)}`
              }
            />
          </dl>

          {row.approval_note && (
            <p className="mt-3 rounded-md bg-surface-alt px-3 py-2 text-xs text-text-muted">
              <span className="font-medium text-text">Approval note: </span>
              {row.approval_note}
            </p>
          )}

          {row.notes && (
            <p className="mt-2 whitespace-pre-line text-xs text-text-muted">{row.notes}</p>
          )}
        </CardBody>
      </Card>

      <ApproveDialog open={dialog === 'approve'} shift={row} onClose={() => setDialog(null)} />
      <ReopenDialog open={dialog === 'reopen'} shift={row} onClose={() => setDialog(null)} />
    </div>
  );
}

function Detail({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-[10px] tracking-wide text-text-subtle uppercase">{label}</dt>
      <dd className="text-sm text-text">{value}</dd>
      {sub && <dd className="text-[11px] text-text-subtle">{sub}</dd>}
    </div>
  );
}

/**
 * Sign a variance off.
 *
 * The note is optional and the figure is untouchable: approving records who agreed
 * that the drawer was short and when. It does not adjust the count, because a
 * signature that could also move the number would be indistinguishable from the
 * error it authorises.
 */
function ApproveDialog({
  open,
  shift,
  onClose,
}: {
  open: boolean;
  shift: RegisterSession;
  onClose: () => void;
}) {
  const [note, setNote] = useState('');
  const approve = useApproveVariance(() => {
    setNote('');
    onClose();
  });

  return (
    <Modal
      open={open}
      onClose={approve.isPending ? () => undefined : onClose}
      title="Approve this variance"
      description={`${shift.number} counted ${formatMoneyString(shift.actual_balance)} against ${formatMoneyString(shift.closing_balance)} expected.`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={approve.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            size="sm"
            icon="checkmark-done-outline"
            loading={approve.isPending}
            onClick={() => approve.mutate({ id: shift.id, note: note.trim() === '' ? null : note.trim() })}
          >
            Approve {formatVariance(shift.variance)}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-text-muted">
          You are signing the count as it stands. The variance stays on the record and the shift
          stays closed — this says the difference is accepted, not that it is fixed.
        </p>
        <Textarea
          label="Note"
          name="approval_note"
          placeholder="Why the difference is accepted — CCTV checked, float error found…"
          value={note}
          onChange={(event) => setNote(event.target.value)}
        />
      </div>
    </Modal>
  );
}

/**
 * Put a closed shift back open, with a reason.
 *
 * The reason is required and it is the only input: a reopen is the one action on a
 * shift that lets the money move again after it was counted, so the record has to
 * say who asked for that and why. The first count is preserved and the approval
 * signature is cleared — a reopened shift has to be counted again by someone.
 */
function ReopenDialog({
  open,
  shift,
  onClose,
}: {
  open: boolean;
  shift: RegisterSession;
  onClose: () => void;
}) {
  const [reason, setReason] = useState('');
  const reopen = useReopenShift(() => {
    setReason('');
    onClose();
  });

  return (
    <Modal
      open={open}
      onClose={reopen.isPending ? () => undefined : onClose}
      title="Reopen this shift"
      description={`${shift.number} was counted at ${formatMoneyString(shift.actual_balance)}.`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={reopen.isPending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            size="sm"
            icon="refresh-outline"
            loading={reopen.isPending}
            disabled={reason.trim() === ''}
            onClick={() => reopen.mutate({ id: shift.id, reason: reason.trim() })}
          >
            Reopen
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-text-muted">
          The drawer starts taking money again and any payment billed while it was shut joins this
          shift. Your first count and the approval signature are kept on the record, and the shift
          will have to be closed and counted again.
        </p>
        <Input
          label="Reason"
          name="reopen_reason"
          placeholder="Cashier's count was wrong — drawer re-counted"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          error={
            reason.length > 500 ? 'Keep the reason under 500 characters.' : apiFieldHint(reopen.error)
          }
        />
      </div>
    </Modal>
  );
}

/** Surface a refusal once, under the field, rather than only in a toast. */
function apiFieldHint(error: unknown): string | undefined {
  if (!error) {
    return undefined;
  }

  return apiErrorMessage(error, '');
}
