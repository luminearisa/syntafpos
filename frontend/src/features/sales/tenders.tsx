import { useState } from 'react';
import type { PaymentMethod } from '@/types';
import { cn, formatMoneyString } from '@/utils/format';
import { moneyText, moneyUnits, roundUpToNote } from './money-math';
import { TENDER_METHODS, type TenderLine } from './tender-model';

/**
 * A single cash-entry row: the amount, and the drawer's note shortcuts.
 *
 * Split out because a sale may be settled by two cash rows and a card — the
 * layout must not assume the first line is the only one a cashier edits.
 */
export function TenderRow({
  tender,
  balance,
  onChange,
  onRemove,
  removable,
}: {
  tender: TenderLine;
  balance: string;
  onChange: (patch: Partial<TenderLine>) => void;
  onRemove: () => void;
  removable: boolean;
}) {
  const [focused, setFocused] = useState(false);
  const notes = quickNotes(balance);

  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-2 rounded-md border px-2.5 py-2',
        focused ? 'border-primary bg-primary-soft/30' : 'border-border bg-surface'
      )}
    >
      <select
        name={`tender_method_${tender.key}`}
        aria-label="Payment method"
        value={tender.method}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        onChange={(event) => onChange({ method: event.target.value as PaymentMethod })}
        className="h-8 rounded-md border border-border bg-surface px-2 text-sm text-text focus:border-primary focus:outline-none"
      >
        {TENDER_METHODS.map((method) => (
          <option key={method.value} value={method.value}>
            {method.label}
          </option>
        ))}
      </select>

      <div className="relative min-w-[8rem] flex-1">
        <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-xs text-text-subtle">
          Rp
        </span>
        <input
          name={`tender_amount_${tender.key}`}
          aria-label="Amount paid"
          inputMode="decimal"
          autoComplete="off"
          value={tender.amount}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          onChange={(event) => onChange({ amount: event.target.value })}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              onChange({ amount: balance });
            }
          }}
          placeholder={balance}
          className="h-8 w-full rounded-md border border-border bg-surface pr-2.5 pl-8 text-right font-mono text-sm text-text focus:border-primary focus:outline-none"
        />
      </div>

      {tender.method === 'cash' ? (
        <div className="relative min-w-[8rem] flex-1">
          <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-xs text-text-subtle">
            in
          </span>
          <input
            name={`tender_tendered_${tender.key}`}
            aria-label="Cash handed over"
            inputMode="decimal"
            autoComplete="off"
            value={tender.tendered}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            onChange={(event) => onChange({ tendered: event.target.value })}
            placeholder="Received"
            className="h-8 w-full rounded-md border border-border bg-surface pr-2.5 pl-8 text-right font-mono text-sm text-text focus:border-primary focus:outline-none"
          />
        </div>
      ) : (
        <span className="min-w-[8rem] flex-1 px-1 text-[11px] text-text-subtle">
          Settled by the terminal — no cash in the drawer.
        </span>
      )}

      {tender.method === 'cash' && (
        <div className="flex flex-wrap gap-1">
          <NoteButton label="Exact" onClick={() => onChange({ tendered: tender.amount || balance })} />
          {notes.map((note) => (
            <NoteButton
              key={note}
              label={formatMoneyString(note)}
              onClick={() => onChange({ tendered: note })}
            />
          ))}
        </div>
      )}

      {removable && (
        <button
          type="button"
          onClick={onRemove}
          aria-label="Remove this payment"
          className="rounded-md p-1 text-text-subtle hover:bg-surface-alt hover:text-danger"
        >
          <ion-icon name="close-outline" class="text-base" aria-hidden="true" />
        </button>
      )}
    </div>
  );
}

function NoteButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="h-8 rounded-md border border-border bg-surface px-2 font-mono text-[11px] text-text-muted hover:border-primary hover:text-primary"
    >
      {label}
    </button>
  );
}

/**
 * The notes that could actually cover a bill: from 1.000 up, smallest first,
 * stopping once the change would exceed the purchase itself.
 */
function quickNotes(balance: string): string[] {
  const rounded = roundUpToNote(balance);
  const units = moneyUnits(balance);

  if (units <= 0) {
    return [];
  }

  const notes = [1_000, 2_000, 5_000, 10_000, 20_000, 50_000, 100_000]
    .filter((note) => note * 10_000 >= units)
    .map((note) => moneyText(note * 10_000));

  // The rounded-up figure is the useful one; a note two steps above it is the
  // cashier counting a bigger stack than the customer is holding.
  return Array.from(new Set([rounded, ...notes])).slice(0, 3);
}
