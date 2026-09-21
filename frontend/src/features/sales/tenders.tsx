import { useState } from 'react';
import type { PaymentMethod } from '@/types';
import { cn, formatMoneyString } from '@/utils/format';
import { moneyText, moneyUnits, roundUpToNote, subMoney } from './money-math';
import { type TenderLine, tenderProblem } from './tender-model';

/**
 * A single tender row, built from the method the shop configured.
 *
 * Split out because a sale may be settled by two cash rows and a QRIS scan — the
 * layout must not assume the first line is the only one a cashier edits.
 *
 * Everything the row *is* comes off the configured method rather than off a
 * hard-coded list: the cash box appears when `takes_tender` says so, the reference
 * box when `requires_reference` does. That is the whole point of the split between
 * a channel and a method — a shop that adds "GoPay" gets a correct row for it here
 * without this file knowing GoPay exists.
 */
export function TenderRow({
  tender,
  methods,
  balance,
  onChange,
  onRemove,
  removable,
}: {
  tender: TenderLine;
  methods: PaymentMethod[];
  balance: string;
  onChange: (patch: Partial<TenderLine>) => void;
  onRemove: () => void;
  removable: boolean;
}) {
  const [focused, setFocused] = useState(false);
  const notes = quickNotes(balance);
  const method = tender.method;
  const problem = tenderProblem(tender);
  const showReference = !!method && (method.requires_reference || tender.reference !== '');
  // Change on a cash line, shown while typing: it is what the cashier hands back,
  // and the server computes the same figure from the same two numbers.
  const change = subMoney(tender.tendered, tender.amount);
  const changeUnits = moneyUnits(change);

  return (
    <div
      className={cn(
        'flex flex-col gap-1.5 rounded-md border px-2.5 py-2',
        problem
          ? 'border-danger/50 bg-danger-soft/30'
          : focused
            ? 'border-primary bg-primary-soft/30'
            : 'border-border bg-surface'
      )}
    >
      <div className="flex flex-wrap items-center gap-2">
        <select
          name={`tender_method_${tender.key}`}
          aria-label="Payment method"
          value={method ? methodKey(method) : ''}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          onChange={(event) => onChange({ method: pickMethod(methods, event.target.value) })}
          className="h-8 min-w-[9rem] rounded-md border border-border bg-surface px-2 text-sm text-text focus:border-primary focus:outline-none"
        >
          <option value="">Payment method…</option>
          {methods.map((entry) => (
            <option key={entry.id ?? entry.code} value={methodKey(entry)}>
              {entry.name}
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

        {/* The drawer, and only the drawer: `takes_tender` is a property of the
            channel, so no configuration can put a cash box behind a card. */}
        {method?.takes_tender ? (
          <div className="relative min-w-[8rem] flex-1">
            <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-xs text-text-subtle">
              in
            </span>
            <input
              name={`tender_tendered_${tender.key}`}
              aria-label="Cash received"
              inputMode="decimal"
              autoComplete="off"
              value={tender.tendered}
              onFocus={() => setFocused(true)}
              onBlur={() => setFocused(false)}
              onChange={(event) => onChange({ tendered: event.target.value })}
              placeholder="Received"
              className="h-8 w-full rounded-md border border-border bg-surface pr-2.5 pl-8 text-right font-mono text-sm text-text focus:border-primary focus:outline-none"
            />
            {changeUnits > 0 && (
              <span className="absolute -bottom-4 right-0 font-mono text-[10px] text-success">
                change {formatMoneyString(change)}
              </span>
            )}
          </div>
        ) : (
          <span className="min-w-[8rem] flex-1 px-1 text-[11px] text-text-subtle">
            {showReference ? 'Reference below' : 'Settled by the terminal — no cash in the drawer.'}
          </span>
        )}

        {method?.takes_tender && (
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

      {showReference && (
        <input
          name={`tender_reference_${tender.key}`}
          aria-label={method?.requires_reference ? 'Reference (required)' : 'Reference'}
          autoComplete="off"
          value={tender.reference}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          onChange={(event) => onChange({ reference: event.target.value })}
          placeholder={method?.requires_reference ? 'Transfer / terminal reference — required' : 'Reference (optional)'}
          className="h-8 rounded-md border border-border bg-surface px-2 text-sm text-text placeholder:text-text-subtle focus:border-primary focus:outline-none"
        />
      )}

      {problem && <span className="text-[11px] text-danger">{problem}</span>}
    </div>
  );
}

/**
 * A stable `<option>` value for a method.
 *
 * A configured row is keyed by its id. A fallback row — what a shop that has
 * configured nothing is offered — has no id, so it is keyed by its code with a
 * prefix that no integer can collide with.
 */
function methodKey(method: PaymentMethod): string {
  return method.id === null ? `code:${method.code}` : `id:${method.id}`;
}

function pickMethod(methods: PaymentMethod[], value: string): PaymentMethod | null {
  if (value === '') {
    return null;
  }

  return methods.find((entry) => methodKey(entry) === value) ?? null;
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
