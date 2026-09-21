import { useState } from 'react';
import type { DiscountType, PosCart } from '@/types';
import { useAutoFocus } from './use-pos-shortcuts';
import { cn, decimalInputValue, formatMoneyString } from '@/utils/format';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';

export interface CartHeaderPatch {
  discount_input?: string | null;
  discount_type?: DiscountType;
  other_charges?: string | null;
  notes?: string | null;
}

interface DiscountModalProps {
  open: boolean;
  onClose: () => void;
  cart: PosCart;
  busy: boolean;
  onSubmit: (patch: CartHeaderPatch) => void;
}

/**
 * F6 — the cart-level discount, plus the header figures that move the total.
 *
 * What is typed here is an input, not a total: the server decides what a 10%
 * discount is actually worth after the line taxes, and echoes the derived
 * figure back. So the preview under the entry is the server's last answer,
 * labelled as one, rather than a second calculation this dialog could get wrong.
 */
export function DiscountModal({ open, onClose, cart, busy, onSubmit }: DiscountModalProps) {
  // The form mounts only while the dialog is open, so its fields always start
  // from this cart's current figures rather than carrying over the last sale.
  return open ? (
    <DiscountForm cart={cart} busy={busy} onSubmit={onSubmit} onClose={onClose} />
  ) : null;
}

function DiscountForm({
  cart,
  busy,
  onSubmit,
  onClose,
}: {
  cart: PosCart;
  busy: boolean;
  onSubmit: (patch: CartHeaderPatch) => void;
  onClose: () => void;
}) {
  const [type, setType] = useState<DiscountType>(cart.discount_type);
  const [amount, setAmount] = useState(decimalInputValue(cart.discount_input));
  const [charges, setCharges] = useState(decimalInputValue(cart.other_charges));
  const [notes, setNotes] = useState(cart.notes ?? '');
  const inputRef = useAutoFocus<HTMLInputElement>(true);

  const apply = () => {
    onSubmit({
      discount_input: amount.trim() === '' ? null : amount.trim(),
      discount_type: type,
      other_charges: charges.trim() === '' ? null : charges.trim(),
      notes: notes.trim() === '' ? null : notes.trim(),
    });
    onClose();
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Cart discount"
      description="Applied after line discounts and taxes; the server works out what it is worth."
      size="sm"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" size="sm" onClick={apply} loading={busy}>
            Apply
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <div>
          <div className="mb-1 flex items-center justify-between">
            <span className="text-xs font-medium text-text-muted">Discount</span>
            <div className="flex rounded-md border border-border bg-surface">
              {(['amount', 'percent'] as const).map((mode) => (
                <button
                  key={mode}
                  type="button"
                  onClick={() => setType(mode)}
                  className={cn(
                    'px-2.5 py-1 text-xs',
                    type === mode ? 'bg-primary text-white' : 'text-text-muted hover:bg-surface-alt'
                  )}
                >
                  {mode === 'amount' ? 'Rp' : '%'}
                </button>
              ))}
            </div>
          </div>
          <Input
            ref={inputRef}
            name="cart_discount"
            inputMode="decimal"
            placeholder={type === 'percent' ? '10' : '10000'}
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            hint="Leave empty to remove the cart discount."
          />
          {Number(cart.discount_total) > 0 && (
            <p className="mt-1 text-[11px] text-text-subtle">
              Currently worth {formatMoneyString(cart.discount_total)} on this cart.
            </p>
          )}
        </div>

        <div>
          <span className="mb-1 block text-xs font-medium text-text-muted">Other charges</span>
          <Input
            name="other_charges"
            inputMode="decimal"
            placeholder="0"
            value={charges}
            onChange={(event) => setCharges(event.target.value)}
            hint="Delivery fee, service charge — added before rounding."
          />
        </div>

        <label className="flex flex-col gap-1">
          <span className="text-xs font-medium text-text-muted">Note for this sale</span>
          <Textarea
            name="cart_notes"
            value={notes}
            rows={2}
            placeholder="e.g. gift wrapping requested"
            onChange={(event) => setNotes(event.target.value)}
          />
        </label>

        <div className="rounded-md bg-surface-alt px-3 py-2 text-xs text-text-muted">
          <div className="flex justify-between">
            <span>Grand total now</span>
            <span className="font-mono text-text">{formatMoneyString(cart.grand_total)}</span>
          </div>
        </div>
      </div>
    </Modal>
  );
}

/** The footer button that opens the discount dialog. */
export function DiscountFooterButton({
  cart,
  onOpen,
}: {
  cart: PosCart;
  onOpen: () => void;
}) {
  const active = Number(cart.discount_total) > 0 || Number(cart.other_charges) > 0;

  return (
    <Button
      variant={active ? 'primary' : 'outline'}
      size="sm"
      icon="pricetag-outline"
      onClick={onOpen}
      title={
        active
          ? `Discount ${formatMoneyString(cart.discount_total)} · other charges ${formatMoneyString(cart.other_charges)}`
          : 'Add a cart discount or other charges'
      }
    >
      {Number(cart.discount_total) > 0
        ? `−${formatMoneyString(cart.discount_total)}`
        : active
          ? 'Charges'
          : 'Discount'}
      <kbd className="ml-1.5 hidden text-[10px] opacity-70 lg:inline">F6</kbd>
    </Button>
  );
}
