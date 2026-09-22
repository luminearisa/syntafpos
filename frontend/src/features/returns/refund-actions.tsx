import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { refundApi } from '@/api/services';
import type { Refund } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { formatMoneyString } from '@/utils/format';
import { useAuthStore } from '@/stores/auth-store';
import { listQueryKeys } from '@/lib/query-client';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/overlay';
import { useToast } from '@/components/ui/toast';

/**
 * The steps a refund can take, as buttons with names on them (Phase 3.5).
 *
 * Each button posts to its own endpoint because each is a decision, not an edit.
 * Which ones appear follows two things: the refund's status and the caller's
 * permissions — a cashier who may process a payout does not get to sign it off,
 * and nobody gets a button for a transition the status machine would refuse.
 *
 * Reject, fail and complete can carry words, so they open a small modal first;
 * approve and process are recorded as they stand.
 */
export function RefundActions({
  refund,
  size = 'sm',
}: {
  refund: Refund;
  size?: 'xs' | 'sm';
}) {
  const can = useAuthStore((state) => state.can);
  const client = useQueryClient();
  const { toast } = useToast();

  const [dialog, setDialog] = useState<null | 'reject' | 'complete' | 'fail'>(null);
  const [text, setText] = useState('');

  const settle = (title: string) => {
    client.invalidateQueries({ queryKey: listQueryKeys.refunds });
    client.invalidateQueries({ queryKey: listQueryKeys.sales });
    client.invalidateQueries({ queryKey: ['sales', refund.sale_id, 'detail'] });
    setDialog(null);
    setText('');
    toast({ variant: 'success', title });
  };

  const fail = (error: unknown, title: string) => {
    toast({
      variant: 'error',
      title,
      message: apiErrorMessage(error, 'The refund was left as it was.'),
    });
  };

  const approve = useMutation({
    mutationFn: () => refundApi.approve(refund.id),
    onSuccess: () => settle(`Refund ${refund.number} approved`),
    onError: (error) => fail(error, 'Could not approve the refund'),
  });

  const process = useMutation({
    mutationFn: () => refundApi.process(refund.id),
    onSuccess: () => settle(`Refund ${refund.number} is processing`),
    onError: (error) => fail(error, 'Could not start the refund'),
  });

  const reject = useMutation({
    mutationFn: () => refundApi.reject(refund.id, text.trim()),
    onSuccess: () => settle(`Refund ${refund.number} rejected`),
    onError: (error) => fail(error, 'Could not reject the refund'),
  });

  const complete = useMutation({
    mutationFn: () => refundApi.complete(refund.id, text),
    onSuccess: () => settle(`Refund ${refund.number} completed`),
    onError: (error) => fail(error, 'Could not complete the refund'),
  });

  const recordFailure = useMutation({
    mutationFn: () => refundApi.fail(refund.id, text),
    onSuccess: () => settle(`Refund ${refund.number} marked failed`),
    onError: (error) => fail(error, 'Could not mark the refund failed'),
  });

  const busy =
    approve.isPending ||
    process.isPending ||
    reject.isPending ||
    complete.isPending ||
    recordFailure.isPending;

  const canApprove = can('refunds.approve');
  const canProcess = can('refunds.process');

  const mayApprove = canApprove && refund.status === 'requested';
  const mayReject = canApprove && refund.status === 'requested';
  const mayProcess = canProcess && refund.status === 'approved';
  const mayComplete =
    canProcess && (refund.status === 'approved' || refund.status === 'processing');
  const mayFail =
    canProcess && (refund.status === 'approved' || refund.status === 'processing');

  if (!mayApprove && !mayReject && !mayProcess && !mayComplete && !mayFail) {
    return <span className="text-xs text-text-subtle">—</span>;
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-1.5">
        {mayApprove && (
          <Button
            size={size}
            variant="primary"
            icon="checkmark-outline"
            loading={approve.isPending}
            disabled={busy}
            onClick={() => approve.mutate()}
          >
            Approve
          </Button>
        )}
        {mayReject && (
          <Button
            size={size}
            variant="outline"
            icon="close-outline"
            disabled={busy}
            onClick={() => {
              reject.reset();
              setText('');
              setDialog('reject');
            }}
          >
            Reject
          </Button>
        )}
        {mayProcess && (
          <Button
            size={size}
            variant="secondary"
            icon="hourglass-outline"
            loading={process.isPending}
            disabled={busy}
            onClick={() => process.mutate()}
          >
            Process
          </Button>
        )}
        {mayComplete && (
          <Button
            size={size}
            variant="success"
            icon="cash-outline"
            disabled={busy}
            onClick={() => {
              complete.reset();
              setText('');
              setDialog('complete');
            }}
          >
            Complete
          </Button>
        )}
        {mayFail && (
          <Button
            size={size}
            variant="ghost"
            icon="alert-circle-outline"
            disabled={busy}
            onClick={() => {
              recordFailure.reset();
              setText('');
              setDialog('fail');
            }}
          >
            Fail
          </Button>
        )}
      </div>

      <Modal
        open={dialog !== null}
        onClose={() => setDialog(null)}
        title={
          dialog === 'reject'
            ? 'Reject this refund?'
            : dialog === 'fail'
              ? 'Mark this refund failed?'
              : 'Complete this refund?'
        }
        description={`${refund.number} · ${formatMoneyString(refund.amount)}`}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)} disabled={busy}>
              Cancel
            </Button>
            {dialog === 'reject' && (
              <Button
                variant="danger"
                loading={reject.isPending}
                disabled={text.trim() === ''}
                onClick={() => reject.mutate()}
              >
                Reject refund
              </Button>
            )}
            {dialog === 'fail' && (
              <Button variant="danger" loading={recordFailure.isPending} onClick={() => recordFailure.mutate()}>
                Mark failed
              </Button>
            )}
            {dialog === 'complete' && (
              <Button variant="success" loading={complete.isPending} onClick={() => complete.mutate()}>
                Pay out
              </Button>
            )}
          </>
        }
      >
        {dialog === 'reject' && (
          <Input
            name="refund_reject_reason"
            label="Reason (required)"
            placeholder="e.g. the goods were not brought back"
            value={text}
            onChange={(event) => setText(event.target.value)}
            autoFocus
          />
        )}
        {dialog === 'fail' && (
          <Input
            name="refund_fail_reason"
            label="What went wrong? (optional)"
            placeholder="e.g. the terminal declined the reversal"
            value={text}
            onChange={(event) => setText(event.target.value)}
            autoFocus
          />
        )}
        {dialog === 'complete' && (
          <div className="flex flex-col gap-2 text-sm text-text-muted">
            <p>
              This writes the money down against the sale&apos;s tenders. It cannot be undone,
              and a correction is raised as a new refund.
            </p>
            <Input
              name="refund_external_reference"
              label="Provider reference (optional)"
              placeholder="e.g. the gateway's transaction id"
              value={text}
              onChange={(event) => setText(event.target.value)}
            />
          </div>
        )}
      </Modal>
    </>
  );
}