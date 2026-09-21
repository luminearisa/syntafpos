import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { purchaseOrderApi, warehouseApi } from '@/api/services';
import type { PurchaseOrder } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { formatDate, formatMoneyString, labelFor } from '@/utils/format';
import { DocumentStatusBadge } from './document-status-badge';
import { apiErrorMessage } from './shared';
import { PurchaseOrderFormDrawer } from './purchase-order-form-drawer';

type OrderAction = 'submit' | 'approve' | 'send' | 'close' | 'cancel';

const actionLabels: Record<OrderAction, string> = {
  submit: 'Purchase order submitted',
  approve: 'Purchase order approved',
  send: 'Purchase order marked as sent',
  close: 'Purchase order closed',
  cancel: 'Purchase order cancelled',
};

const statusOptions = (
  [
    'draft',
    'submitted',
    'approved',
    'sent',
    'partially_received',
    'received',
    'closed',
    'cancelled',
  ] as const
).map((value) => ({ value, label: labelFor.documentStatus(value) }));

export default function PurchaseOrdersPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<PurchaseOrder>(
    listQueryKeys.purchaseOrders,
    (params) => purchaseOrderApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'po-filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<PurchaseOrder | null>(null);
  const [removeTarget, setRemoveTarget] = useState<PurchaseOrder | null>(null);
  const [cancelTarget, setCancelTarget] = useState<PurchaseOrder | null>(null);

  // A single mutation drives the state machine so every transition shares one
  // pending flag and one invalidation path.
  const transition = useMutation({
    mutationFn: ({ id, action }: { id: number; action: OrderAction }) => {
      switch (action) {
        case 'submit':
          return purchaseOrderApi.submit(id);
        case 'approve':
          return purchaseOrderApi.approve(id);
        case 'send':
          return purchaseOrderApi.send(id);
        case 'close':
          return purchaseOrderApi.close(id);
        case 'cancel':
          return purchaseOrderApi.cancel(id);
      }
    },
    onSuccess: (_data, variables) => {
      toast({ title: actionLabels[variables.action], variant: 'success' });
      invalidate(listQueryKeys.purchaseOrders);
      if (variables.action === 'cancel') {
        setCancelTarget(null);
      }
    },
    onError: (error, variables) => {
      toast({
        title: 'Could not update the purchase order',
        message: apiErrorMessage(error, 'Failed to update the purchase order'),
        variant: 'error',
      });
      if (variables.action === 'cancel') {
        setCancelTarget(null);
      }
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => purchaseOrderApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Purchase order deleted', variant: 'success' });
      invalidate(listQueryKeys.purchaseOrders);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete purchase order',
        message: apiErrorMessage(error, 'Failed to delete purchase order'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (order: PurchaseOrder) => {
    setEditTarget(order);
    setFormOpen(true);
  };

  const runTransition = (order: PurchaseOrder, action: OrderAction) => {
    transition.reset();
    transition.mutate({ id: order.id, action });
  };

  const columns: Column<PurchaseOrder>[] = [
    {
      key: 'number',
      header: 'Number',
      sortable: true,
      width: '10rem',
      render: (row) => <span className="font-mono text-xs">{row.number}</span>,
    },
    {
      key: 'supplier',
      header: 'Supplier',
      render: (row) => (
        <span className="font-medium text-text">
          {row.supplier?.name ?? `Supplier #${row.supplier_id}`}
        </span>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse?.name ?? '-'}</span>
      ),
    },
    {
      key: 'order_date',
      header: 'Order date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.order_date)}</span>
      ),
    },
    {
      key: 'expected_date',
      header: 'Expected date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.expected_date)}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <DocumentStatusBadge status={row.status} />,
    },
    {
      key: 'items',
      header: 'Items',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">
          {row.items_count ?? row.items?.length ?? 0}
        </span>
      ),
    },
    {
      key: 'grand_total',
      header: 'Grand total',
      align: 'right',
      render: (row) => (
        <span className="whitespace-nowrap font-medium text-text">
          {formatMoneyString(row.grand_total, row.currency)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => {
        const status = row.status;
        const busy = transition.isPending;

        return (
          <div className="flex items-center justify-end gap-0.5">
            {status === 'draft' && can('purchases.update') && (
              <IconButton
                icon="send-outline"
                label="Submit for approval"
                disabled={busy}
                onClick={() => runTransition(row, 'submit')}
              />
            )}
            {status === 'submitted' && can('purchases.approve') && (
              <IconButton
                icon="checkmark-done-outline"
                label="Approve order"
                disabled={busy}
                onClick={() => runTransition(row, 'approve')}
              />
            )}
            {status === 'approved' && can('purchases.update') && (
              <IconButton
                icon="paper-plane-outline"
                label="Mark as sent"
                disabled={busy}
                onClick={() => runTransition(row, 'send')}
              />
            )}
            {status === 'received' && can('purchases.approve') && (
              <IconButton
                icon="archive-outline"
                label="Close order"
                disabled={busy}
                onClick={() => runTransition(row, 'close')}
              />
            )}
            {status === 'draft' && can('purchases.cancel') && (
              <IconButton
                icon="ban-outline"
                label="Cancel order"
                disabled={busy}
                className="hover:text-danger"
                onClick={() => {
                  transition.reset();
                  setCancelTarget(row);
                }}
              />
            )}
            {status === 'draft' && can('purchases.update') && (
              <IconButton
                icon="create-outline"
                label={`Edit ${row.number}`}
                onClick={() => openEdit(row)}
              />
            )}
            {status === 'draft' && can('purchases.delete') && (
              <IconButton
                icon="trash-outline"
                label={`Delete ${row.number}`}
                className="hover:text-danger"
                onClick={() => {
                  removeMutation.reset();
                  setRemoveTarget(row);
                }}
              />
            )}
          </div>
        );
      },
    },
  ];

  const canCreate = can('purchases.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load purchase orders'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Purchase orders"
        description="Raise supplier orders, track approval, and match them against receipts."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon="add-outline"
              onClick={openCreate}
              disabled={companyId === null}
            >
              New purchase order
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="po_status_filter"
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
          label="Warehouse"
          name="po_warehouse_filter"
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
        searchPlaceholder="Search order numbers..."
        emptyTitle="No purchase orders yet"
        emptyDescription="Raise a purchase order to start buying from a supplier."
        emptyAction={
          canCreate
            ? {
                label: 'New purchase order',
                onClick: openCreate,
                icon: 'add-outline',
              }
            : undefined
        }
      />

      <PurchaseOrderFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={cancelTarget !== null}
        onClose={() => setCancelTarget(null)}
        onConfirm={() =>
          cancelTarget && transition.mutate({ id: cancelTarget.id, action: 'cancel' })
        }
        title="Cancel purchase order"
        message={
          <>
            Cancel <strong className="text-text">{cancelTarget?.number}</strong>? A
            cancelled order can no longer be edited or received against.
          </>
        }
        confirmLabel="Cancel order"
        variant="danger"
        loading={transition.isPending}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete purchase order"
        message={
          <>
            Delete <strong className="text-text">{removeTarget?.number}</strong>? This
            removes the draft and its lines; the action cannot be undone.
          </>
        }
        confirmLabel="Delete order"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
