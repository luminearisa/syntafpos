import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { customerApi } from '@/api/services';
import type { Customer, PaginationMeta, PosCart } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useAutoFocus } from './use-pos-shortcuts';
import { cn, formatMoneyString } from '@/utils/format';
import { Modal } from '@/components/ui/overlay';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { EmptyState, LoadingState } from '@/components/ui/state';

interface CustomerPickerModalProps {
  open: boolean;
  onClose: () => void;
  cart: PosCart;
  busy: boolean;
  /** Attach a customer, or pass null to go back to a walk-in sale. */
  onPick: (customer: Customer | null) => void;
}

/**
 * F4 — who is this sale for?
 *
 * A cashier searches by whatever they have: a name, a phone number, or a
 * member card code, and the backend search covers all three. The row shows the
 * price list the customer is billed at, because that is the fact that changes
 * the numbers on screen the moment they are picked. An outstanding balance is
 * not shown: receivables arrive with a later phase, and there is no honest
 * figure to render yet.
 */
export function CustomerPickerModal({
  open,
  onClose,
  cart,
  busy,
  onPick,
}: CustomerPickerModalProps) {
  // Body mounts with the dialog, so a new search starts from an empty box
  // rather than the last customer typed.
  return open ? (
    <CustomerSearch onClose={onClose} cart={cart} busy={busy} onPick={onPick} />
  ) : null;
}

function CustomerSearch({
  onClose,
  cart,
  busy,
  onPick,
}: Omit<CustomerPickerModalProps, 'open'>) {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const [term, setTerm] = useState('');
  const debounced = useDebouncedValue(term.trim(), 250);
  const searchRef = useAutoFocus<HTMLInputElement>(true);

  const { data, isFetching } = useQuery({
    queryKey: ['pos', 'customers', companyId, debounced],
    queryFn: () =>
      customerApi.list({
        company_id: companyId ?? undefined,
        search: debounced || undefined,
        per_page: 20,
        sort: 'name',
        direction: 'asc',
      }),
  });

  const customers = data?.data ?? [];
  const meta = data?.meta as PaginationMeta | undefined;

  return (
    <Modal
      open
      onClose={onClose}
      title="Customer"
      description="Prices follow the customer's price list. A walk-in sale uses the default tier."
      size="md"
    >
      <div className="flex flex-col gap-3">
        <Input
          ref={searchRef}
          name="customer_search"
          icon="search-outline"
          placeholder="Name, phone, or customer code..."
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          aria-label="Search customers"
        />

        <div className="flex max-h-[50vh] flex-col gap-1 overflow-y-auto">
          <button
            type="button"
            disabled={busy || cart.customer_id === null}
            onClick={() => {
              onPick(null);
              onClose();
            }}
            className={cn(
              'flex items-center gap-2 rounded-md border px-3 py-2 text-left text-sm',
              cart.customer_id === null
                ? 'border-primary bg-primary-soft text-primary'
                : 'border-border text-text-muted hover:bg-surface-alt',
              'disabled:cursor-default disabled:opacity-60'
            )}
          >
            <ion-icon name="person-outline" class="text-lg" aria-hidden="true" />
            <span className="flex-1">Walk-in customer</span>
            {cart.customer_id === null && <span className="text-xs">current</span>}
          </button>

          {isFetching ? (
            <LoadingState label="Searching customers..." className="py-6" />
          ) : customers.length === 0 ? (
            <EmptyState
              icon="people-outline"
              title="No customers found"
              description={debounced ? `Nothing matches “${debounced}”.` : 'This company has no active customers yet.'}
              className="py-6"
            />
          ) : (
            customers.map((customer) => (
              <CustomerRow
                key={customer.id}
                customer={customer}
                current={cart.customer_id === customer.id}
                busy={busy}
                onPick={() => {
                  onPick(customer);
                  onClose();
                }}
              />
            ))
          )}
        </div>

        {meta && meta.last_page > 1 && (
          <p className="text-center text-[11px] text-text-subtle">
            {meta.total} matches — keep typing to narrow it down.
          </p>
        )}
      </div>
    </Modal>
  );
}

function CustomerRow({
  customer,
  current,
  busy,
  onPick,
}: {
  customer: Customer;
  current: boolean;
  busy: boolean;
  onPick: () => void;
}) {
  return (
    <button
      type="button"
      disabled={busy}
      onClick={onPick}
      className={cn(
        'flex items-center gap-3 rounded-md border px-3 py-2 text-left transition-colors',
        current ? 'border-primary bg-primary-soft' : 'border-border hover:bg-surface-alt',
        'disabled:cursor-not-allowed disabled:opacity-60'
      )}
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium text-text">{customer.name}</span>
        <span className="block truncate font-mono text-[11px] text-text-subtle">
          {customer.customer_code}
          {customer.phone ? ` · ${customer.phone}` : ''}
        </span>
      </span>

      <span className="shrink-0 text-right">
        <span className="block text-xs text-text-muted">
          {customer.price_list?.name ?? 'Default prices'}
        </span>
        {Number(customer.credit_limit) > 0 && (
          <span className="block text-[11px] text-text-subtle">
            Limit {formatMoneyString(customer.credit_limit)}
          </span>
        )}
      </span>
    </button>
  );
}

/** The footer button that opens the picker, showing who is currently picked. */
export function CustomerFooterButton({
  cart,
  onOpen,
  onClear,
  busy,
}: {
  cart: PosCart;
  onOpen: () => void;
  onClear: () => void;
  busy: boolean;
}) {
  const customer = cart.customer;

  return (
    <div className="flex min-w-0 items-center gap-1">
      <Button
        variant={customer ? 'primary' : 'outline'}
        size="sm"
        icon="person-outline"
        onClick={onOpen}
        className="min-w-0 max-w-56"
      >
        <span className="truncate">{customer ? customer.name : 'Walk-in'}</span>
        <kbd className="ml-1.5 hidden text-[10px] opacity-70 lg:inline">F4</kbd>
      </Button>

      {customer && (
        <Button
          variant="ghost"
          size="xs"
          icon="close-outline"
          onClick={onClear}
          disabled={busy}
          aria-label="Remove customer"
          title={`Phone ${customer.phone ?? '-'}, code ${customer.customer_code}, prices: ${customer.price_list?.name ?? 'default'}`}
        />
      )}
    </div>
  );
}
