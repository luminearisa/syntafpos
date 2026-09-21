import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { stockOpnameApi, warehouseApi } from '@/api/services';
import type { StockOpname, StockOpnameStatus } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { formatDate, labelFor } from '@/utils/format';
import { StockOpnameFormDrawer } from './stock-opname-form-drawer';

type BadgeVariant =
  | 'default'
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'outline';

type OpnameAction = 'count' | 'review' | 'approve' | 'post';

/** The status a transition lands in, used to word the confirmation toast. */
const RESULTING_STATUS: Record<OpnameAction, StockOpnameStatus> = {
  count: 'counting',
  review: 'review',
  approve: 'approved',
  post: 'posted',
};

interface NextAction {
  action: OpnameAction;
  label: string;
  icon: string;
  permission: string;
}

/**
 * draft → counting → review → approved → posted.
 * Each step has exactly one endpoint, and a posted or cancelled sheet is done.
 */
const NEXT_ACTION: Partial<Record<StockOpnameStatus, NextAction>> = {
  draft: {
    action: 'count',
    label: 'Start counting',
    icon: 'create-outline',
    permission: 'inventory.opname',
  },
  counting: {
    action: 'review',
    label: 'Submit for review',
    icon: 'send-outline',
    permission: 'inventory.opname',
  },
  review: {
    action: 'approve',
    label: 'Approve',
    icon: 'checkmark-circle-outline',
    permission: 'inventory.approve',
  },
  approved: {
    action: 'post',
    label: 'Post',
    icon: 'archive-outline',
    permission: 'inventory.approve',
  },
};

const STATUS_VARIANT: Record<string, BadgeVariant> = {
  draft: 'default',
  counting: 'info',
  review: 'warning',
  approved: 'primary',
  posted: 'success',
  cancelled: 'danger',
};

const STATUS_OPTIONS: { label: string; value: StockOpnameStatus }[] = [
  'draft',
  'counting',
  'review',
  'approved',
  'posted',
].map((value) => ({
  value: value as StockOpnameStatus,
  label: labelFor.documentStatus(value),
}));

function apiErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };

    const errors = axiosError.response?.data?.errors;
    if (errors) {
      const first = Object.values(errors)[0];
      if (first && first.length > 0) {
        return first[0] ?? fallback;
      }
    }

    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }
  }

  if (error instanceof Error) {
    return error.message;
  }

  return fallback;
}

export default function StockOpnamesPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<StockOpname>(
    listQueryKeys.stockOpnames,
    (params) => stockOpnameApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      warehouse_id: undefined,
      status: undefined,
      page: 1,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [removeTarget, setRemoveTarget] = useState<StockOpname | null>(null);

  const transition = useMutation({
    mutationFn: (vars: { id: number; action: OpnameAction }) =>
      stockOpnameApi[vars.action](vars.id),
    onSuccess: (_data, vars) => {
      toast({
        title: 'Count sheet updated',
        message: `Moved to ${labelFor.documentStatus(RESULTING_STATUS[vars.action])}`,
        variant: 'success',
      });
      invalidate(listQueryKeys.stockOpnames);
    },
    onError: (error) => {
      toast({
        title: 'Could not update the count sheet',
        message: apiErrorMessage(error, 'Failed to update the stock count'),
        variant: 'error',
      });
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => stockOpnameApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Count sheet deleted', variant: 'success' });
      invalidate(listQueryKeys.stockOpnames);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete the count sheet',
        message: apiErrorMessage(error, 'Failed to delete the stock count'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setFormOpen(true);
  };

  const openRemove = (opname: StockOpname) => {
    removeMutation.reset();
    setRemoveTarget(opname);
  };

  const columns: Column<StockOpname>[] = [
    {
      key: 'number',
      header: 'Number',
      sortable: true,
      width: '10rem',
      render: (row) => <span className="font-mono text-xs">{row.number}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => {
        const variant = row.status ? STATUS_VARIANT[row.status] : 'outline';

        return (
          <Badge variant={variant}>{labelFor.documentStatus(row.status)}</Badge>
        );
      },
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{row.warehouse?.name ?? '-'}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.warehouse?.code ?? '-'}
          </span>
        </div>
      ),
    },
    {
      key: 'opname_date',
      header: 'Count date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.opname_date)}</span>
      ),
    },
    {
      key: 'items_count',
      header: 'Lines',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {row.items_count ?? row.items?.length ?? 0}
        </span>
      ),
    },
    {
      key: 'counted_by',
      header: 'Counted by',
      render: (row) =>
        row.counted_by ? (
          <span className="font-mono text-xs text-text-muted">user #{row.counted_by}</span>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'created_at',
      header: 'Created',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.created_at)}</span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => {
        const next = row.status ? NEXT_ACTION[row.status] : undefined;
        const isBusy =
          transition.isPending && transition.variables?.id === row.id;

        return (
          <div className="flex items-center justify-end gap-1">
            {next && can(next.permission) && (
              <Button
                variant="outline"
                size="xs"
                icon={next.icon}
                loading={isBusy}
                onClick={() =>
                  transition.mutate({ id: row.id, action: next.action })
                }
              >
                {next.label}
              </Button>
            )}

            {row.status === 'draft' && can('inventory.opname') && (
              <IconButton
                icon="trash-outline"
                label={`Delete ${row.number}`}
                className="hover:text-danger"
                onClick={() => openRemove(row)}
              />
            )}
          </div>
        );
      },
    },
  ];

  const canCreate = can('inventory.opname');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load stock counts'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Stock counts"
        description="Count sheets reconcile the ledger against what is really on the shelf, then post any variance as a movement."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New count
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Warehouse"
          name="warehouse_filter"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              warehouse_id:
                event.target.value === '' ? undefined : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />

        <Select
          label="Status"
          name="status_filter"
          options={STATUS_OPTIONS}
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
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search count sheets..."
        emptyTitle="No stock counts yet"
        emptyDescription="Open a count sheet for a warehouse to start reconciling the ledger against the shelf."
        emptyAction={
          canCreate
            ? { label: 'New count', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <StockOpnameFormDrawer open={formOpen} onClose={() => setFormOpen(false)} />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete count sheet"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.number}</strong>? Only an
            uncounted draft can be removed, and this cannot be undone.
          </>
        }
        confirmLabel="Delete count sheet"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
