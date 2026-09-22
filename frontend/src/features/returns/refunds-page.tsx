import { useNavigate } from 'react-router-dom';
import { refundApi } from '@/api/services';
import type { Refund, RefundMethod, RefundStatus } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import { formatDate, formatMoneyString, labelFor } from '@/utils/format';
import { RefundActions } from './refund-actions';
import { RefundStatusBadge } from './status-badges';

const REFUND_STATUSES: RefundStatus[] = [
  'requested',
  'approved',
  'processing',
  'completed',
  'failed',
  'rejected',
];

const REFUND_METHODS: RefundMethod[] = ['cash', 'original_payment', 'manual', 'gateway'];

const statusOptions = REFUND_STATUSES.map((value) => ({
  value,
  label: labelFor.refundStatus(value),
}));

const methodOptions = REFUND_METHODS.map((value) => ({
  value,
  label: labelFor.refundMethod(value),
}));

/**
 * Refunds (Phase 3.5).
 *
 * Money going back to customers, and the queue of decisions around it. Each row
 * carries the actions its status allows and the caller's permissions permit —
 * a refund over the shop's threshold waits here for a manager to approve it, and
 * the `Requires approval` flag says which ones those are.
 */
export default function RefundsPage() {
  const navigate = useNavigate();

  const list = useListQuery<Refund>(
    listQueryKeys.refunds,
    (params) => refundApi.list(params)
  );

  const columns: Column<Refund>[] = [
    {
      key: 'number',
      header: 'Refund',
      sortable: true,
      width: '11rem',
      render: (row) => <span className="font-mono text-xs text-text">{row.number}</span>,
    },
    {
      key: 'sale',
      header: 'Sale',
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
      key: 'method',
      header: 'Method',
      render: (row) => (
        <span className="text-text-muted">{row.method_label || labelFor.refundMethod(row.method)}</span>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (row) => (
        <span className="whitespace-nowrap font-mono tabular-nums font-medium text-text">
          {formatMoneyString(row.amount)}
        </span>
      ),
    },
    {
      key: 'requested',
      header: 'Requested',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{formatDate(row.requested_at, true)}</span>
          <span className="text-[11px] text-text-subtle">
            {row.requested_by_user?.name ?? '-'}
          </span>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <div className="flex flex-col items-start gap-1">
          <RefundStatusBadge status={row.status} />
          {row.approval_required && row.status === 'requested' && (
            <Badge variant="warning" icon="shield-outline">
              Needs approval
            </Badge>
          )}
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'right',
      render: (row) => <RefundActions refund={row} />,
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Refunds"
        description="Money given back to customers. Approve, process and pay out from here; a completed refund cannot be edited."
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="refund_status_filter"
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
        <Select
          label="Method"
          name="refund_method_filter"
          options={methodOptions}
          placeholder="All methods"
          value={list.params.method ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              method: event.target.value === '' ? undefined : event.target.value,
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
        error={list.error ? list.error.message || 'Failed to load refunds' : null}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search refund numbers..."
        emptyTitle="No refunds yet"
        emptyDescription="Raise a refund against a sale when money has to go back."
      />
    </div>
  );
}