import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { purchaseReturnApi, warehouseApi } from '@/api/services';
import type { PurchaseReturn } from '@/types';
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
import { PurchaseReturnFormDrawer } from './purchase-return-form-drawer';

const statusOptions = (['draft', 'posted'] as const).map((value) => ({
  value,
  label: labelFor.documentStatus(value),
}));

export default function PurchaseReturnsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<PurchaseReturn>(
    listQueryKeys.purchaseReturns,
    (params) => purchaseReturnApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'prt-filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<PurchaseReturn | null>(null);
  const [removeTarget, setRemoveTarget] = useState<PurchaseReturn | null>(null);
  const [postTarget, setPostTarget] = useState<PurchaseReturn | null>(null);

  // Posting moves stock back out of the ledger, so it is a deliberate action
  // with a confirmation rather than a one-click transition.
  const postMutation = useMutation({
    mutationFn: (id: number) => purchaseReturnApi.post(id),
    onSuccess: () => {
      toast({ title: 'Purchase return posted', variant: 'success' });
      invalidate(listQueryKeys.purchaseReturns);
      setPostTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not post the purchase return',
        message: apiErrorMessage(error, 'Failed to post the purchase return'),
        variant: 'error',
      });
      setPostTarget(null);
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => purchaseReturnApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Purchase return deleted', variant: 'success' });
      invalidate(listQueryKeys.purchaseReturns);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete purchase return',
        message: apiErrorMessage(error, 'Failed to delete purchase return'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (purchaseReturn: PurchaseReturn) => {
    setEditTarget(purchaseReturn);
    setFormOpen(true);
  };

  const columns: Column<PurchaseReturn>[] = [
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
      key: 'goods_receipt',
      header: 'Receipt',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">
          {row.goods_receipt?.number ?? '-'}
        </span>
      ),
    },
    {
      key: 'return_date',
      header: 'Return date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.return_date)}</span>
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
      key: 'total_amount',
      header: 'Total',
      align: 'right',
      render: (row) => (
        <span className="whitespace-nowrap font-medium text-text">
          {formatMoneyString(row.total_amount)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {row.status === 'draft' && can('purchases.receive') && (
            <IconButton
              icon="checkmark-circle-outline"
              label="Post return"
              disabled={postMutation.isPending}
              onClick={() => {
                postMutation.reset();
                setPostTarget(row);
              }}
            />
          )}
          {row.status === 'draft' && can('purchases.receive') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.number}`}
              disabled={postMutation.isPending}
              onClick={() => openEdit(row)}
            />
          )}
          {row.status === 'draft' && can('purchases.receive') && (
            <IconButton
              icon="trash-outline"
              label={`Delete ${row.number}`}
              className="hover:text-danger"
              disabled={postMutation.isPending}
              onClick={() => {
                removeMutation.reset();
                setRemoveTarget(row);
              }}
            />
          )}
        </div>
      ),
    },
  ];

  const canCreate = can('purchases.receive');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load purchase returns'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Purchase returns"
        description="Send goods back to suppliers. Posting a return moves stock out of the ledger."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon="add-outline"
              onClick={openCreate}
              disabled={companyId === null}
            >
              New purchase return
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="prt_status_filter"
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
          name="prt_warehouse_filter"
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
        searchPlaceholder="Search return numbers..."
        emptyTitle="No purchase returns yet"
        emptyDescription="Record a purchase return when goods go back to a supplier."
        emptyAction={
          canCreate
            ? {
                label: 'New purchase return',
                onClick: openCreate,
                icon: 'add-outline',
              }
            : undefined
        }
      />

      <PurchaseReturnFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={postTarget !== null}
        onClose={() => setPostTarget(null)}
        onConfirm={() => postTarget && postMutation.mutate(postTarget.id)}
        title="Post purchase return"
        message={
          <>
            Post <strong className="text-text">{postTarget?.number}</strong>? Stock
            moves back out of the ledger, and a posted return can no longer be
            edited.
          </>
        }
        confirmLabel="Post return"
        variant="primary"
        loading={postMutation.isPending}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete purchase return"
        message={
          <>
            Delete <strong className="text-text">{removeTarget?.number}</strong>? This
            removes the draft and its lines; the action cannot be undone.
          </>
        }
        confirmLabel="Delete return"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
