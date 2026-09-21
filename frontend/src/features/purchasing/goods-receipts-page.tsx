import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { goodsReceiptApi, warehouseApi } from '@/api/services';
import type { GoodsReceipt } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { formatDate, labelFor } from '@/utils/format';
import { DocumentStatusBadge } from './document-status-badge';
import { apiErrorMessage } from './shared';
import { GoodsReceiptFormDrawer } from './goods-receipt-form-drawer';

const statusOptions = (['draft', 'posted'] as const).map((value) => ({
  value,
  label: labelFor.documentStatus(value),
}));

export default function GoodsReceiptsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<GoodsReceipt>(
    listQueryKeys.goodsReceipts,
    (params) => goodsReceiptApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'gr-filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<GoodsReceipt | null>(null);
  const [removeTarget, setRemoveTarget] = useState<GoodsReceipt | null>(null);
  const [postTarget, setPostTarget] = useState<GoodsReceipt | null>(null);

  // Posting moves stock into the ledger, so it is a deliberate action with a
  // confirmation rather than a one-click transition.
  const postMutation = useMutation({
    mutationFn: (id: number) => goodsReceiptApi.post(id),
    onSuccess: () => {
      toast({ title: 'Goods receipt posted', variant: 'success' });
      invalidate(listQueryKeys.goodsReceipts);
      setPostTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not post the goods receipt',
        message: apiErrorMessage(error, 'Failed to post the goods receipt'),
        variant: 'error',
      });
      setPostTarget(null);
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => goodsReceiptApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Goods receipt deleted', variant: 'success' });
      invalidate(listQueryKeys.goodsReceipts);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete goods receipt',
        message: apiErrorMessage(error, 'Failed to delete goods receipt'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (receipt: GoodsReceipt) => {
    setEditTarget(receipt);
    setFormOpen(true);
  };

  const columns: Column<GoodsReceipt>[] = [
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
      key: 'purchase_order',
      header: 'Order',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">
          {row.purchase_order?.number ?? '-'}
        </span>
      ),
    },
    {
      key: 'receipt_date',
      header: 'Receipt date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.receipt_date)}</span>
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
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {row.status === 'draft' && can('purchases.receive') && (
            <IconButton
              icon="checkmark-circle-outline"
              label="Post receipt"
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
    ? list.error.message || 'Failed to load goods receipts'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Goods receipts"
        description="Record arrivals against purchase orders. Posting a receipt moves stock into the ledger."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon="add-outline"
              onClick={openCreate}
              disabled={companyId === null}
            >
              New goods receipt
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="gr_status_filter"
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
          name="gr_warehouse_filter"
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
        searchPlaceholder="Search receipt numbers..."
        emptyTitle="No goods receipts yet"
        emptyDescription="Record a goods receipt when a supplier delivery arrives."
        emptyAction={
          canCreate
            ? {
                label: 'New goods receipt',
                onClick: openCreate,
                icon: 'add-outline',
              }
            : undefined
        }
      />

      <GoodsReceiptFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={postTarget !== null}
        onClose={() => setPostTarget(null)}
        onConfirm={() => postTarget && postMutation.mutate(postTarget.id)}
        title="Post goods receipt"
        message={
          <>
            Post <strong className="text-text">{postTarget?.number}</strong>? Stock
            moves into the ledger at the recorded costs, and a posted receipt can
            no longer be edited.
          </>
        }
        confirmLabel="Post receipt"
        variant="primary"
        loading={postMutation.isPending}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete goods receipt"
        message={
          <>
            Delete <strong className="text-text">{removeTarget?.number}</strong>? This
            removes the draft and its lines; the action cannot be undone.
          </>
        }
        confirmLabel="Delete receipt"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
