import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { warehouseApi, warehouseTransferApi } from '@/api/services';
import type { TransferStatus, WarehouseTransfer } from '@/types';
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
import { WarehouseTransferFormDrawer } from './warehouse-transfer-form-drawer';

type BadgeVariant =
  | 'default'
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'outline';

type TransferAction = 'submit' | 'approve' | 'ship' | 'receive' | 'complete';

interface NextAction {
  action: TransferAction;
  label: string;
  icon: string;
  permission: string;
}

/**
 * draft → submitted → approved → shipped → received → completed, plus cancel.
 * Each step has exactly one endpoint; a completed or cancelled transfer is done.
 */
const NEXT_ACTION: Partial<Record<TransferStatus, NextAction>> = {
  draft: {
    action: 'submit',
    label: 'Submit',
    icon: 'send-outline',
    permission: 'inventory.transfer',
  },
  submitted: {
    action: 'approve',
    label: 'Approve',
    icon: 'checkmark-circle-outline',
    permission: 'inventory.approve',
  },
  approved: {
    action: 'ship',
    label: 'Ship',
    icon: 'cube-outline',
    permission: 'inventory.transfer',
  },
  shipped: {
    action: 'receive',
    label: 'Receive',
    icon: 'arrow-down-circle-outline',
    permission: 'inventory.transfer',
  },
  received: {
    action: 'complete',
    label: 'Complete',
    icon: 'checkmark-circle-outline',
    permission: 'inventory.transfer',
  },
};

/** The status a transition lands in, used to word the confirmation toast. */
const RESULTING_STATUS: Record<TransferAction, TransferStatus> = {
  submit: 'submitted',
  approve: 'approved',
  ship: 'shipped',
  receive: 'received',
  complete: 'completed',
};

const STATUS_VARIANT: Record<string, BadgeVariant> = {
  draft: 'default',
  submitted: 'info',
  approved: 'primary',
  shipped: 'warning',
  received: 'info',
  completed: 'success',
  cancelled: 'danger',
};

const STATUS_OPTIONS: { label: string; value: TransferStatus }[] = [
  'draft',
  'submitted',
  'approved',
  'shipped',
  'received',
  'completed',
  'cancelled',
].map((value) => ({
  value: value as TransferStatus,
  label: labelFor.documentStatus(value),
}));

/** A transfer can be cancelled any time before it leaves the source warehouse. */
const CANCELLABLE: TransferStatus[] = ['draft', 'submitted', 'approved'];

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

export default function WarehouseTransfersPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<WarehouseTransfer>(
    listQueryKeys.warehouseTransfers,
    (params) => warehouseTransferApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      from_warehouse_id: undefined,
      to_warehouse_id: undefined,
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
  const [cancelTarget, setCancelTarget] = useState<WarehouseTransfer | null>(null);

  const transition = useMutation({
    mutationFn: (vars: { id: number; action: TransferAction }) =>
      warehouseTransferApi[vars.action](vars.id),
    onSuccess: (_data, vars) => {
      toast({
        title: 'Transfer updated',
        message: `Moved to ${labelFor.documentStatus(RESULTING_STATUS[vars.action])}`,
        variant: 'success',
      });
      invalidate(listQueryKeys.warehouseTransfers);
    },
    onError: (error) => {
      toast({
        title: 'Could not update the transfer',
        message: apiErrorMessage(error, 'Failed to update the transfer'),
        variant: 'error',
      });
    },
  });

  const cancelMutation = useMutation({
    mutationFn: (id: number) => warehouseTransferApi.cancel(id),
    onSuccess: () => {
      toast({ title: 'Transfer cancelled', variant: 'success' });
      invalidate(listQueryKeys.warehouseTransfers);
      setCancelTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not cancel the transfer',
        message: apiErrorMessage(error, 'Failed to cancel the transfer'),
        variant: 'error',
      });
      setCancelTarget(null);
    },
  });

  const openCreate = () => {
    setFormOpen(true);
  };

  const openCancel = (transfer: WarehouseTransfer) => {
    cancelMutation.reset();
    setCancelTarget(transfer);
  };

  const columns: Column<WarehouseTransfer>[] = [
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
      key: 'transfer_date',
      header: 'Transfer date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.transfer_date)}</span>
      ),
    },
    {
      key: 'from_warehouse',
      header: 'From',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{row.from_warehouse?.name ?? '-'}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.from_warehouse?.code ?? '-'}
          </span>
        </div>
      ),
    },
    {
      key: 'to_warehouse',
      header: 'To',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{row.to_warehouse?.name ?? '-'}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.to_warehouse?.code ?? '-'}
          </span>
        </div>
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
        const cancellable =
          row.status !== null &&
          CANCELLABLE.includes(row.status) &&
          can('inventory.transfer');

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

            {cancellable && (
              <IconButton
                icon="close-circle-outline"
                label={`Cancel ${row.number}`}
                className="hover:text-danger"
                onClick={() => openCancel(row)}
              />
            )}
          </div>
        );
      },
    },
  ];

  const canCreate = can('inventory.transfer');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load warehouse transfers'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Warehouse transfers"
        description="Move stock between warehouses, track it in transit and reconcile the receipt against the shipment."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New transfer
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="From"
          name="from_warehouse_filter"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.from_warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              from_warehouse_id:
                event.target.value === '' ? undefined : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />

        <Select
          label="To"
          name="to_warehouse_filter"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.to_warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              to_warehouse_id:
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
        searchPlaceholder="Search transfers..."
        emptyTitle="No warehouse transfers"
        emptyDescription="Raise a transfer to move stock between warehouses; the ledger writes an out movement on shipment and an in movement on receipt."
        emptyAction={
          canCreate
            ? { label: 'New transfer', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <WarehouseTransferFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
      />

      <ConfirmModal
        open={cancelTarget !== null}
        onClose={() => setCancelTarget(null)}
        onConfirm={() =>
          cancelTarget && cancelMutation.mutate(cancelTarget.id)
        }
        title="Cancel transfer"
        message={
          <>
            Are you sure you want to cancel{' '}
            <strong className="text-text">{cancelTarget?.number}</strong>? A
            cancelled transfer is abandoned and cannot be shipped.
          </>
        }
        confirmLabel="Cancel transfer"
        variant="danger"
        loading={cancelMutation.isPending}
      />
    </div>
  );
}
