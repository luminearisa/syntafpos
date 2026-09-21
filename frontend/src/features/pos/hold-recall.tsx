import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { posApi } from '@/api/services';
import type { HeldCart } from '@/types';
import { HELD_QUERY_KEY } from './use-pos-cart';
import { useHeldCarts } from './use-pos-cart';
import { useAutoFocus } from './use-pos-shortcuts';
import { apiErrorMessage } from '@/utils/api-error';
import { formatMoneyString } from '@/utils/format';
import { useToast } from '@/components/ui/toast';
import { Modal, ConfirmModal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState, LoadingState } from '@/components/ui/state';
import type { PosCart } from '@/types';

/**
 * F8 — park the current cart so the next customer can be served.
 *
 * The label rides along on the cart itself (the hold endpoint only flips the
 * status), so a label here is saved first and the hold follows. It is what
 * makes the queue readable when three carts are waiting: "Bu Sari — back soon"
 * beats a serial number. An empty cart is refused by the server, which is
 * exactly why the dialog can show the totals and let the cashier decide.
 */
export function HoldDialog({
  open,
  onClose,
  cart,
  busy,
  onHold,
}: {
  open: boolean;
  onClose: () => void;
  cart: PosCart;
  busy: boolean;
  onHold: (label: string | null) => void;
}) {
  const [label, setLabel] = useState('');
  const inputRef = useAutoFocus<HTMLInputElement>(open);

  if (!open) {
    return null;
  }

  const confirm = () => {
    onHold(label.trim() === '' ? null : label.trim());
    setLabel('');
    onClose();
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Hold this cart?"
      description="The sale is parked and the till opens for the next customer. Nothing is reserved and no stock moves."
      size="sm"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" size="sm" icon="pause-circle-outline" onClick={confirm} loading={busy}>
            Hold
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <Input
          ref={inputRef}
          name="hold_label"
          label="Label (optional)"
          placeholder="e.g. Bu Sari — waiting for cash"
          value={label}
          onChange={(event) => setLabel(event.target.value)}
        />
        <p className="text-xs text-text-muted">
          {cart.items?.length ?? 0} {cart.items?.length === 1 ? 'line' : 'lines'} ·{' '}
          {formatMoneyString(cart.grand_total)}
        </p>
        {cart.items.length === 0 && (
          <p className="text-xs text-warning">
            This cart is empty — the server will refuse to hold it.
          </p>
        )}
      </div>
    </Modal>
  );
}

/**
 * F9 — the parked-cart queue.
 *
 * Recall puts a parked cart back on this register; tapping the row or typing
 * the code the cashier reads out are the same path. Discarding is offered here
 * because held drafts carry no inventory or revenue, so removing one is safe —
 * it still asks first, because the lines cannot be recovered.
 */
export function RecallDialog({
  open,
  onClose,
  busy,
  onRecall,
}: {
  open: boolean;
  onClose: () => void;
  busy: boolean;
  onRecall: (number: string) => void;
}) {
  const { toast } = useToast();
  const client = useQueryClient();
  const [code, setCode] = useState('');
  const [deleting, setDeleting] = useState<HeldCart | null>(null);
  const codeRef = useAutoFocus<HTMLInputElement>(open);

  const { data, isFetching } = useHeldCarts(open);
  const held = data?.data ?? [];

  const discard = useMutation({
    mutationFn: (cartId: number) => posApi.removeHeld(cartId),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: HELD_QUERY_KEY });
      toast({ variant: 'success', title: 'Held cart discarded' });
      setDeleting(null);
    },
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot discard that cart', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  if (!open) {
    return null;
  }

  const submit = () => {
    const number = code.trim().toUpperCase();

    if (number) {
      onRecall(number);
      onClose();
    }
  };

  return (
    <>
      <Modal
        open
        onClose={onClose}
        title="Held carts"
        description="Recall moves a parked cart onto this register. The current cart must be empty or held first."
        size="md"
      >
        <div className="flex flex-col gap-3">
          <div className="flex items-end gap-2">
            <Input
              ref={codeRef}
              name="recall_code"
              placeholder="PARK-20260921-0001"
              value={code}
              onChange={(event) => setCode(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  submit();
                }
              }}
              wrapperClassName="flex-1"
              aria-label="Hold code"
            />
            <Button variant="primary" onClick={submit} loading={busy} disabled={code.trim() === ''}>
              Recall
            </Button>
          </div>

          {isFetching ? (
            <LoadingState label="Loading held carts..." className="py-6" />
          ) : held.length === 0 ? (
            <EmptyState
              icon="pause-circle-outline"
              title="Nothing on hold"
              description="Use Hold (F8) to park a cart and free the register."
              className="py-6"
            />
          ) : (
            <ul className="flex max-h-[45vh] flex-col gap-1 overflow-y-auto">
              {held.map((cart) => (
                <li
                  key={cart.id}
                  className="flex items-center gap-3 rounded-md border border-border px-3 py-2"
                >
                  <button
                    type="button"
                    onClick={() => {
                      if (cart.number) {
                        onRecall(cart.number);
                        onClose();
                      }
                    }}
                    disabled={busy || !cart.number}
                    className="min-w-0 flex-1 text-left"
                  >
                    <span className="flex items-baseline gap-2">
                      <span className="font-mono text-xs text-primary">{cart.number}</span>
                      <span className="truncate text-sm font-medium text-text">
                        {cart.label ?? cart.customer?.name ?? 'Walk-in'}
                      </span>
                    </span>
                    <span className="mt-0.5 block truncate text-[11px] text-text-subtle">
                      {cart.item_count} {cart.item_count === 1 ? 'line' : 'lines'}
                      {cart.held_at
                        ? ` · ${new Date(cart.held_at).toLocaleString('id-ID', {
                            day: '2-digit',
                            month: 'short',
                            hour: '2-digit',
                            minute: '2-digit',
                          })}`
                        : ''}
                      {cart.cashier ? ` · ${cart.cashier.name}` : ''}
                    </span>
                  </button>

                  <span className="shrink-0 text-sm font-semibold text-text">
                    {formatMoneyString(cart.grand_total)}
                  </span>

                  <Button
                    variant="ghost"
                    size="xs"
                    icon="trash-outline"
                    onClick={() => setDeleting(cart)}
                    aria-label={`Discard ${cart.number ?? 'held cart'}`}
                    className="shrink-0 text-danger"
                  />
                </li>
              ))}
            </ul>
          )}
        </div>
      </Modal>

      <ConfirmModal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && discard.mutate(deleting.id)}
        loading={discard.isPending}
        title="Discard held cart?"
        confirmLabel="Discard"
        message={
          deleting
            ? `${deleting.number} (${deleting.item_count} lines) will be removed. Held carts are drafts, so stock and accounting are untouched — but the lines are gone.`
            : ''
        }
      />
    </>
  );
}
