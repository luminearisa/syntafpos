import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { registerApi, registerSessionApi } from '@/api/services';
import type { ListParams, RegisterSession } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { formatDate, formatMoneyString } from '@/utils/format';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import { OpenShiftDialog } from './open-shift-dialog';
import { formatDuration, formatVariance } from './shift-model';

/** Three tabs over one endpoint: what is running, what needs signing, what is done. */
type Tab = 'open' | 'awaiting' | 'closed';

const TABS: { key: Tab; label: string; params: ListParams }[] = [
  { key: 'open', label: 'Open', params: { status: 'open' } },
  { key: 'awaiting', label: 'Awaiting approval', params: { awaiting_approval: '1' } },
  { key: 'closed', label: 'Closed', params: { status: 'closed' } },
];

/**
 * Shift history and the variance queue (Phase 3.4).
 *
 * A shift list is read in two directions, and the tabs are those two. A cashier or
 * owner scans the open and closed rows to see what a till took and how it counted;
 * a manager opens "Awaiting approval" because that is the work waiting for a
 * signature, and it is the only queue on this screen where the point of the row is
 * the figure in the Variance column.
 *
 * The variance shown here is the stored one — what the count was measured against
 * at close — because the list does not recompute a shift's ledger per row. Opening
 * a row is where the live figures are.
 */
export default function ShiftsPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const can = useAuthStore((state) => state.can);
  const [tab, setTab] = useState<Tab>('open');
  const [opening, setOpening] = useState(false);

  const list = useListQuery<RegisterSession>(
    listQueryKeys.registerSessions,
    (params) => registerSessionApi.list(params),
    TABS.find((entry) => entry.key === tab)?.params ?? {}
  );

  // Changing the active company must not leave the previous company's register
  // filter applied to the new list.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      register_id: undefined,
      cashier_id: undefined,
      page: 1,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const pickTab = (next: Tab) => {
    setTab(next);
    list.onParamsChange({
      status: next === 'awaiting' ? undefined : next,
      awaiting_approval: next === 'awaiting' ? '1' : undefined,
      page: 1,
    });
  };

  const { data: registerOptionsData } = useQuery({
    queryKey: ['registers', 'filter-options', companyId],
    queryFn: () => registerApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });

  const columns: Column<RegisterSession>[] = [
    {
      key: 'number',
      header: 'Shift',
      render: (row) => (
        <Link to={`/registers/shifts/${row.id}`} className="font-mono text-xs text-primary hover:underline">
          {row.number}
        </Link>
      ),
    },
    {
      key: 'register',
      header: 'Register',
      render: (row) => (
        <span className="text-xs text-text-muted">
          {row.register_code ?? '-'}
          <span className="block text-[10px] text-text-subtle">{row.branch_name}</span>
        </span>
      ),
    },
    {
      key: 'cashier',
      header: 'Cashier',
      render: (row) => <span className="text-xs text-text">{row.cashier?.name ?? '-'}</span>,
    },
    {
      key: 'opened_at',
      header: 'Opened',
      render: (row) => <span className="text-xs text-text-muted">{formatDate(row.opened_at, true)}</span>,
    },
    {
      key: 'closed_at',
      header: 'Closed',
      render: (row) => (
        <span className="text-xs text-text-muted">
          {formatDate(row.closed_at, true)}
          {row.closed_at && (
            <span className="block text-[10px] text-text-subtle">
              {formatDuration(row.duration_minutes)}
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'opening_balance',
      header: 'Float',
      align: 'right',
      render: (row) => (
        <span className="font-mono tabular-nums text-text-muted">
          {formatMoneyString(row.opening_balance)}
        </span>
      ),
    },
    {
      key: 'closing_balance',
      header: 'Expected',
      align: 'right',
      render: (row) => (
        <span className="font-mono tabular-nums text-text-muted">
          {row.closing_balance === null ? '—' : formatMoneyString(row.closing_balance)}
        </span>
      ),
    },
    {
      key: 'actual_balance',
      header: 'Counted',
      align: 'right',
      render: (row) => (
        <span className="font-mono tabular-nums text-text">
          {row.actual_balance === null ? '—' : formatMoneyString(row.actual_balance)}
        </span>
      ),
    },
    {
      key: 'variance',
      header: 'Variance',
      align: 'right',
      render: (row) => <VarianceCell row={row} />,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <ShiftBadge row={row} />,
    },
  ];

  const errorMessage = list.error ? list.error.message || 'Failed to load shifts' : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Cash register shifts"
        description="Every drawer that has been opened, counted and signed off. Open a row for its report and its cash movements."
        actions={
          can('register_sessions.open') && (
            <Button size="sm" icon="lock-open-outline" onClick={() => setOpening(true)}>
              Open register
            </Button>
          )
        }
      />

      <div className="flex flex-wrap items-center gap-1 rounded-lg border border-border bg-surface p-1">
        {TABS.map((entry) => (
          <button
            key={entry.key}
            type="button"
            onClick={() => pickTab(entry.key)}
            className={
              'rounded-md px-3 py-1.5 text-xs font-medium transition-colors ' +
              (tab === entry.key
                ? 'bg-primary-soft text-primary'
                : 'text-text-muted hover:bg-surface-alt hover:text-text')
            }
          >
            {entry.label}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Register"
          name="shift_register_filter"
          options={(registerOptionsData?.data ?? []).map((register) => ({
            label: register.name,
            value: register.id,
          }))}
          placeholder="All registers"
          value={list.params.register_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              register_id: event.target.value === '' ? undefined : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[12rem]"
          disabled={companyId === null}
        />

        <Input
          type="date"
          label="Opened from"
          name="shift_date_from"
          value={list.params.date_from ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_from: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9.5rem]"
        />

        <Input
          type="date"
          label="to"
          name="shift_date_to"
          value={list.params.date_to ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_to: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9.5rem]"
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
        searchPlaceholder="Shift number, cashier…"
        emptyTitle="No shifts here yet"
        emptyDescription="A shift appears once a register is opened with float on the till."
      />

      <OpenShiftDialog open={opening} onClose={() => setOpening(false)} />
    </div>
  );
}

/**
 * Variance, coloured by whether it needs a signature rather than by its sign.
 *
 * A shortage and a surplus are both a difference; the row a manager has to act on
 * is the one over tolerance and unsigned, and that is what reads as a warning.
 */
function VarianceCell({ row }: { row: RegisterSession }) {
  if (row.variance === null) {
    return <span className="text-text-subtle">—</span>;
  }

  const even = Number(row.variance) === 0;
  const outstanding = row.awaiting_approval;

  return (
    <span
      className={
        'font-mono tabular-nums ' +
        (even ? 'text-text-subtle' : outstanding ? 'font-semibold text-danger' : 'text-warning')
      }
      title={
        row.variance_threshold === null
          ? undefined
          : `Tolerance ${formatMoneyString(row.variance_threshold)} at close`
      }
    >
      {formatVariance(row.variance)}
    </span>
  );
}

function ShiftBadge({ row }: { row: RegisterSession }) {
  if (row.status === 'open') {
    return (
      <Badge variant="success" icon="radio-button-on-outline">
        Open
      </Badge>
    );
  }

  if (row.awaiting_approval) {
    return (
      <Badge variant="warning" icon="person-check-outline">
        Awaiting approval
      </Badge>
    );
  }

  if (row.is_approved) {
    return (
      <Badge variant="primary" icon="checkmark-done-outline" title={`Approved by ${row.approved_by ?? 'a supervisor'}`}>
        Approved
      </Badge>
    );
  }

  return (
    <Badge variant="outline" icon="lock-closed-outline">
      Closed{row.reopen_count > 0 ? ` · reopened ${row.reopen_count}×` : ''}
    </Badge>
  );
}
