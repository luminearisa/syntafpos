import { useNavigate } from 'react-router-dom';
import { saleReturnApi } from '@/api/services';
import type { SaleReturn, SaleReturnStatus } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { IconButton } from '@/components/ui/overlay';
import { Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import { formatDate, formatMoneyString, labelFor } from '@/utils/format';
import { SaleReturnStatusBadge } from './status-badges';

const RETURN_STATUSES: SaleReturnStatus[] = ['draft', 'completed', 'cancelled'];

const statusOptions = RETURN_STATUSES.map((value) => ({
  value,
  label: labelFor.saleReturnStatus(value),
}));

/**
 * Sales returns (Phase 3.5).
 *
 * Every slip that recorded goods coming back, newest first, readable the way a
 * reconciliation needs: by status, by number, by date. Raising one happens on
 * the sale it reverses — here there is only the reading, because a return is
 * created against a sale and never edited afterwards.
 */
export default function ReturnsPage() {
  const navigate = useNavigate();

  const list = useListQuery<SaleReturn>(
    listQueryKeys.saleReturns,
    (params) => saleReturnApi.list(params)
  );

  const columns: Column<SaleReturn>[] = [
    {
      key: 'number',
      header: 'Return',
      sortable: true,
      width: '11rem',
      render: (row) => <span className="font-mono text-xs text-text">{row.number}</span>,
    },
    {
      key: 'sale',
      header: 'Original sale',
      render: (row) => (
        <button
          type="button"
          className="font-mono text-xs text-primary hover:underline"
          onClick={() => navigate(`/sales/${row.sale_id}`)}
        >
          {row.sale?.number ?? `Sale #${row.sale_id}`}
        </button>
      ),
    },
    {
      key: 'return_date',
      header: 'Returned',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.return_date, true)}</span>
      ),
    },
    {
      key: 'returned_by',
      header: 'Received by',
      render: (row) => (
        <span className="text-text-muted">{row.returned_by_user?.name ?? '-'}</span>
      ),
    },
    {
      key: 'items',
      header: 'Items',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">{row.item_count ?? row.items?.length ?? 0}</span>
      ),
    },
    {
      key: 'grand_total',
      header: 'Value',
      align: 'right',
      render: (row) => (
        <span className="whitespace-nowrap font-mono tabular-nums font-medium text-text">
          {formatMoneyString(row.grand_total)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <SaleReturnStatusBadge status={row.status} />,
    },
    {
      key: 'reason',
      header: 'Reason',
      render: (row) => (
        <span className="line-clamp-1 max-w-xs text-xs text-text-muted">{row.reason ?? '-'}</span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <IconButton
          icon="open-outline"
          label={`Open sale for ${row.number}`}
          onClick={() => navigate(`/sales/${row.sale_id}`)}
        />
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Sales returns"
        description="Goods received back from customers. A return puts stock back through the ledger; raising one happens on its sale."
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="return_status_filter"
          options={statusOptions}
          placeholder="All statuses"
          value={list.params.status ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              status: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
        />
      </div>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id}
        loading={list.isLoading}
        error={list.error ? list.error.message || 'Failed to load returns' : null}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search return numbers..."
        emptyTitle="No returns yet"
        emptyDescription="When a customer brings something back, record the return on its sale."
      />
    </div>
  );
}