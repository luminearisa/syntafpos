import { useState } from 'react';
import type { PosCart, PosCartItem } from '@/types';
import { stepQuantity } from './use-pos-cart';
import { cn, decimalInputValue, formatDecimal, formatMoneyString } from '@/utils/format';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { IconButton, Tooltip } from '@/components/ui/overlay';
import { EmptyState } from '@/components/ui/state';

interface CartPanelProps {
  cart: PosCart;
  busy: boolean;
  onQuantity: (item: PosCartItem, quantity: string) => void;
  onDiscount: (item: PosCartItem, discount: string, type: 'amount' | 'percent') => void;
  onNotes: (item: PosCartItem, notes: string) => void;
  onRemove: (item: PosCartItem) => void;
  onClear: () => void;
}

/**
 * The right half of the till: the lines, with the server's totals under them.
 *
 * A line is laid out for a thumb and a scanner, not for a spreadsheet — the
 * quantity is the only figure a cashier edits often, so it gets the big centre
 * control, while the totals a cashier only reads sit right-aligned.
 *
 * Note the totals are rendered from the cart the server last returned. Nothing
 * here adds up the visible lines, which is what keeps the screen and checkout
 * agreeing even when a discount or a rounding rule moves.
 */
export function CartPanel({
  cart,
  busy,
  onQuantity,
  onDiscount,
  onNotes,
  onRemove,
  onClear,
}: CartPanelProps) {
  const items = cart.items ?? [];
  const held = cart.status === 'held';

  return (
    <section
      className="flex h-full min-h-0 w-full flex-1 flex-col bg-surface"
      aria-label="Cart"
    >
      <header className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
        <div className="min-w-0">
          <h2 className="truncate text-sm font-semibold text-text">
            {cart.customer?.name ?? 'Walk-in customer'}
          </h2>
          <p className="truncate text-xs text-text-subtle">
            {items.length} {items.length === 1 ? 'line' : 'lines'} ·{' '}
            {formatDecimal(cart.total_quantity ?? '0', 3)} units
            {cart.register ? ` · ${cart.register.code}` : ''}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-1">
          {held && <Badge variant="warning">On hold</Badge>}
          <Button
            variant="ghost"
            size="xs"
            icon="trash-outline"
            onClick={onClear}
            disabled={busy || items.length === 0}
          >
            Clear
          </Button>
        </div>
      </header>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {items.length === 0 ? (
          <EmptyState
            icon="cart-outline"
            title="Nothing rung up yet"
            description="Scan a barcode, or tap a product on the left."
            className="py-16"
          />
        ) : (
          <ul className="divide-y divide-border">
            {items.map((item) => (
              <CartLine
                key={item.id}
                item={item}
                busy={busy}
                onQuantity={onQuantity}
                onDiscount={onDiscount}
                onNotes={onNotes}
                onRemove={onRemove}
              />
            ))}
          </ul>
        )}
      </div>

      <CartTotals cart={cart} />
    </section>
  );
}

function CartLine({
  item,
  busy,
  onQuantity,
  onDiscount,
  onNotes,
  onRemove,
}: {
  item: PosCartItem;
  busy: boolean;
  onQuantity: (item: PosCartItem, quantity: string) => void;
  onDiscount: (item: PosCartItem, discount: string, type: 'amount' | 'percent') => void;
  onNotes: (item: PosCartItem, notes: string) => void;
  onRemove: (item: PosCartItem) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  // Every editable figure follows the same shape: while the field has focus the
  // cashier's own text wins, and the moment it is left the field falls back to
  // what the server actually has. That way a scan merging onto this line, or a
  // write landing out of order, surfaces as soon as the cashier looks elsewhere
  // — and a half-typed "1" on the way to "100" is never erased.
  const [quantityEdit, setQuantityEdit] = useState<string | null>(null);
  const [discountEdit, setDiscountEdit] = useState<string | null>(null);
  const [notesEdit, setNotesEdit] = useState<string | null>(null);

  const quantity = quantityEdit ?? decimalInputValue(item.quantity);
  const discount = discountEdit ?? decimalInputValue(item.discount);
  const notes = notesEdit ?? (item.notes ?? '');

  const commitQuantity = (next: string) => {
    setQuantityEdit(null);

    if (next === '' || next === decimalInputValue(item.quantity)) {
      return;
    }

    onQuantity(item, next);
  };

  const commitDiscount = (next: string) => {
    setDiscountEdit(null);

    if (next !== decimalInputValue(item.discount)) {
      onDiscount(item, next || '0', item.discount_type);
    }
  };

  const commitNotes = () => {
    setNotesEdit(null);

    if (notes !== (item.notes ?? '')) {
      onNotes(item, notes);
    }
  };

  return (
    <li className="px-3 py-2">
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1">
          <button
            type="button"
            onClick={() => setExpanded((value) => !value)}
            className="block w-full text-left"
            aria-expanded={expanded}
          >
            <span className="line-clamp-2 text-sm font-medium text-text">
              {item.product_name}
              {item.variant_name ? (
                <span className="ml-1 text-xs text-text-muted">· {item.variant_name}</span>
              ) : null}
            </span>
            <span className="mt-0.5 flex flex-wrap items-center gap-x-2 font-mono text-[11px] text-text-subtle">
              <span className="truncate">{item.product_sku}</span>
              {item.barcode && <span className="truncate">{item.barcode}</span>}
              <span className="truncate">
                {formatMoneyString(item.unit_price)}
                {item.unit_code ? ` / ${item.unit_code}` : ''}
              </span>
            </span>
          </button>
        </div>

        <div className="flex shrink-0 flex-col items-end">
          <span className="text-sm font-semibold text-text">
            {formatMoneyString(item.line_total)}
          </span>
          {Number(item.tax_amount) > 0 && (
            <span className="text-[11px] text-text-subtle">
              {item.tax_mode === 'inclusive' ? 'incl.' : '+'}{' '}
              {formatMoneyString(item.tax_amount)} tax
            </span>
          )}
        </div>
      </div>

      <div className="mt-1.5 flex items-center gap-1">
        <div className="flex items-center rounded-md border border-border">
          <IconButton
            icon="remove-outline"
            label={`Decrease ${item.product_name}`}
            disabled={busy}
            onClick={() => onQuantity(item, stepQuantity(item.quantity, -1))}
            className="h-7 w-7 rounded-none"
          />
          <input
            aria-label={`Quantity for ${item.product_name}`}
            value={quantity}
            inputMode="decimal"
            onChange={(event) => setQuantityEdit(event.target.value)}
            onBlur={(event) => commitQuantity(event.target.value.trim())}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                commitQuantity(quantity.trim());
              }
            }}
            className={cn(
              'h-7 w-14 border-x border-border bg-surface text-center text-sm text-text',
              'focus:outline-none focus:ring-1 focus:ring-inset focus:ring-primary'
            )}
          />
          <IconButton
            icon="add-outline"
            label={`Increase ${item.product_name}`}
            disabled={busy}
            onClick={() => onQuantity(item, stepQuantity(item.quantity, 1))}
            className="h-7 w-7 rounded-none"
          />
        </div>

        <span className="ml-auto text-[11px] text-text-subtle">
          {formatMoneyString(item.line_subtotal)}
          {Number(item.discount_amount) > 0 && (
            <span className="text-danger"> · −{formatMoneyString(item.discount_amount)}</span>
          )}
        </span>
      </div>

      {expanded && (
        <div className="mt-2 flex flex-col gap-2 rounded-md bg-surface-alt p-2">
          <div className="flex items-end gap-2">
            <label className="flex flex-1 flex-col gap-1 text-xs text-text-muted">
              Line discount
              <div className="flex gap-1">
                <input
                  type="number"
                  min="0"
                  step="0.0001"
                  value={discount}
                  onChange={(event) => setDiscountEdit(event.target.value)}
                  onBlur={(event) => commitDiscount(event.target.value)}
                  className="h-8 w-full rounded-md border border-border bg-surface px-2 text-sm text-text focus:border-primary focus:outline-none"
                />
                <div className="flex rounded-md border border-border bg-surface">
                  {(['amount', 'percent'] as const).map((mode) => (
                    <button
                      key={mode}
                      type="button"
                      onClick={() => onDiscount(item, decimalInputValue(item.discount) || '0', mode)}
                      className={cn(
                        'px-2 text-xs',
                        item.discount_type === mode
                          ? 'bg-primary text-white'
                          : 'text-text-muted hover:bg-surface-alt'
                      )}
                    >
                      {mode === 'amount' ? 'Rp' : '%'}
                    </button>
                  ))}
                </div>
              </div>
            </label>
          </div>

          <label className="flex flex-col gap-1 text-xs text-text-muted">
            Note for this line
            <input
              value={notes}
              placeholder="no ice, extra shot…"
              onChange={(event) => setNotesEdit(event.target.value)}
              onBlur={commitNotes}
              className="h-8 rounded-md border border-border bg-surface px-2 text-sm text-text focus:border-primary focus:outline-none"
            />
          </label>

          <div className="flex items-center justify-between">
            <Button
              variant="ghost"
              size="xs"
              icon="close-circle-outline"
              onClick={() => onRemove(item)}
              disabled={busy}
              className="text-danger"
            >
              Remove line
            </Button>

            <Tooltip content="The unit price comes from the price engine for this customer.">
              <span className="font-mono text-[11px] text-text-subtle">
                {item.price_source}
              </span>
            </Tooltip>
          </div>
        </div>
      )}
    </li>
  );
}

function CartTotals({ cart }: { cart: PosCart }) {
  const rows: Array<{ label: string; value: string; negative?: boolean; muted?: boolean }> = [
    { label: 'Subtotal', value: cart.subtotal },
  ];

  if (Number(cart.item_discount_total) > 0) {
    rows.push({ label: 'Item discounts', value: cart.item_discount_total, negative: true, muted: true });
  }
  if (Number(cart.discount_total) > 0) {
    rows.push({
      label: cart.discount_type === 'percent'
        ? `Discount (${decimalInputValue(cart.discount_input)}%)`
        : 'Discount',
      value: cart.discount_total,
      negative: true,
      muted: true,
    });
  }
  rows.push({
    label: Number(cart.tax_included_total) > 0 ? 'Tax (added)' : 'Tax',
    value: cart.tax_total,
  });
  if (Number(cart.other_charges) > 0) {
    rows.push({ label: 'Other charges', value: cart.other_charges });
  }
  if (Number(cart.tax_included_total) > 0) {
    rows.push({
      label: 'Tax in prices',
      value: cart.tax_included_total,
      muted: true,
    });
  }
  if (decimalInputValue(cart.rounding) !== '' && Number(cart.rounding) !== 0) {
    rows.push({ label: 'Rounding', value: cart.rounding, muted: true });
  }

  return (
    <div className="border-t border-border px-3 py-2">
      <dl className="flex flex-col gap-0.5">
        {rows.map((row) => (
          <div key={row.label} className="flex items-baseline justify-between gap-2 text-xs">
            <dt className={cn(row.muted ? 'text-text-subtle' : 'text-text-muted')}>{row.label}</dt>
            <dd className={cn('font-mono', row.muted ? 'text-text-subtle' : 'text-text')}>
              {/* The sign lives outside the formatter, which only knows how to
                  group digits — never re-parse its output. */}
              {row.negative ? '−' : ''}
              {formatMoneyString(row.value)}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
