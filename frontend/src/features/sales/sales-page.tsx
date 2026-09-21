import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { registerApi, saleApi } from '@/api/services';
import type { Sale, SaleStatus } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Input, Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import { formatDate, formatMoneyString, labelFor } from '@/utils/format';
import { moneyUnits } from './money-math';
import { SaleStatusBadge } from './sale-status-badge';

/** The six states a sale can be in, in the order the transaction walks them. */
const SALE_STATUSES: SaleStatus[] = [
  'draft',
  'pending_payment',
  'partially_paid',
  'paid',
  'completed',
  'cancelled',
];

const statusOptions = SALE_STATUSES.map((value) => ({
  value,
  label: labelFor.saleStatus(value),
}));

/**
 * Sales and invoices (Phase 3.2).
 *
 * Every sale the till produced, readable the way a shift gets reconciled: by
 * date, by register, by status, by customer. Rows link to the invoice itself,
 * which is where payment, cancellation and printing happen — this list is a
 * finding tool, not an editor, because a posted sale is not editable.
 *
 * The money columns come straight off the sale's own snapshot, so a ticket's
 * figures here are identical to the ones on its printed receipt.
 */
export default function SalesPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);

  const list = useListQuery<Sale>(listQueryKeys.sales, (params) => saleApi.list(params));

  // Changing the active company in the topbar must not leave the previous
  // company's filters (its registers especially) applied to the new list.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, register_id: undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: registerOptionsData } = useQuery({
    queryKey: ['registers', 'filter-options', companyId],
    queryFn: () => registerApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const registerOptions = (registerOptionsData?.data ?? []).map((register) => ({
    label: register.name,
    value: register.id,
  }));

  const columns: Column<Sale>[] = [
    {
      key: 'number',
      header: 'Invoice',
      render: (row) => (
        <Link
          to={`/sales/${row.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.number}
        </Link>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      render: (row) => <span className="text-text-muted">{formatDate(row.date)}</span>,
    },
    {
      key: 'customer',
      header: 'Customer',
      render: (row) => (
        <span className="text-text">{row.customer.name ?? 'Walk-in'}</span>
      ),
    },
    {
      key: 'register',
      header: 'Register',
      render: (row) => (
        <span className="text-xs text-text-muted">{row.register?.name ?? '-'}</span>
      ),
    },
    {
      key: 'cashier',
      header: 'Cashier',
      render: (row) => <span className="text-xs text-text-muted">{row.cashier?.name ?? '-'}</span>,
    },
    {
      key: 'item_count',
      header: 'Lines',
      align: 'right',
      render: (row) => <span className="tabular-nums text-text-muted">{row.item_count ?? 0}</span>,
    },
    {
      key: 'grand_total',
      header: 'Total',
      align: 'right',
      render: (row) => (
        <span className="font-mono tabular-nums text-text">
          {formatMoneyString(row.grand_total)}
        </span>
      ),
    },
    {
      key: 'paid_total',
      header: 'Paid',
      align: 'right',
      render: (row) => (
        <span className="font-mono tabular-nums text-text-muted">
          {formatMoneyString(row.paid_total)}
        </span>
      ),
    },
    {
      key: 'balance_due',
      header: 'Balance',
      align: 'right',
      render: (row) =>
        moneyUnits(row.balance_due) > 0 ? (
          <span className="font-mono tabular-nums font-medium text-warning">
            {formatMoneyString(row.balance_due)}
          </span>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <SaleStatusBadge status={row.status} />,
    },
  ];

  const errorMessage = list.error ? list.error.message || 'Failed to load sales' : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Sales"
        description="Every invoice the till has issued. Open a row for its lines, payments, printing and cancellation."
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="sale_status_filter"
          options={statusOptions}
          placeholder="All statuses"
          value={list.params.status ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              status: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[12rem]"
        />

        <Select
          label="Register"
          name="sale_register_filter"
          options={registerOptions}
          placeholder="All registers"
          value={list.params.register_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              register_id:
                event.target.value === '' ? undefined : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[12rem]"
          disabled={companyId === null}
        />

        <Input
          type="date"
          label="From"
          name="sale_date_from"
          value={list.params.date_from ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_from: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9rem]"
        />

        <Input
          type="date"
          label="To"
          name="sale_date_to"
          value={list.params.date_to ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_to: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9rem]"
        />
      </div>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Invoice number, customer, product…"
        emptyTitle="No sales yet"
        emptyDescription="Sales appear here once a till checks a cart out."
      />
    </div>
  );
}
