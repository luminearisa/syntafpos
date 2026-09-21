import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { purchaseRequestApi, warehouseApi } from '@/api/services';
import type { PurchaseRequest } from '@/types';
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
import { PurchaseRequestFormDrawer } from './purchase-request-form-drawer';

type RequestAction = 'submit' | 'approve' | 'reject' | 'convert';

const actionLabels: Record<RequestAction, string> = {
  submit: 'Purchase request submitted',
  approve: 'Purchase request approved',
  reject: 'Purchase request rejected',
  convert: 'Purchase request converted to an order',
};

const statusOptions = (
  ['draft', 'submitted', 'approved', 'rejected', 'converted'] as const
).map((value) => ({ value, label: labelFor.documentStatus(value) }));

export default function PurchaseRequestsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<PurchaseRequest>(
    listQueryKeys.purchaseRequests,
    (params) => purchaseRequestApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'pr-filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<PurchaseRequest | null>(null);
  const [removeTarget, setRemoveTarget] = useState<PurchaseRequest | null>(null);
  const [rejectTarget, setRejectTarget] = useState<PurchaseRequest | null>(null);

  // A single mutation drives the whole state machine so every transition shares
  // one pending flag and one invalidation path.
  const transition = useMutation({
    mutationFn: ({ id, action }: { id: number; action: RequestAction }) => {
      switch (action) {
        case 'submit':
          return purchaseRequestApi.submit(id);
        case 'approve':
          return purchaseRequestApi.approve(id);
        case 'reject':
          return purchaseRequestApi.reject(id);
        case 'convert':
          return purchaseRequestApi.convert(id);
      }
    },
    onSuccess: (_data, variables) => {
      toast({ title: actionLabels[variables.action], variant: 'success' });
      invalidate(listQueryKeys.purchaseRequests);
      if (variables.action === 'reject') {
        setRejectTarget(null);
      }
    },
    onError: (error, variables) => {
      toast({
        title: 'Could not update the purchase request',
        message: apiErrorMessage(error, 'Failed to update the purchase request'),
        variant: 'error',
      });
      if (variables.action === 'reject') {
        setRejectTarget(null);
      }
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => purchaseRequestApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Purchase request deleted', variant: 'success' });
      invalidate(listQueryKeys.purchaseRequests);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete purchase request',
        message: apiErrorMessage(error, 'Failed to delete purchase request'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (request: PurchaseRequest) => {
    setEditTarget(request);
    setFormOpen(true);
  };

  const runTransition = (request: PurchaseRequest, action: RequestAction) => {
    transition.reset();
    transition.mutate({ id: request.id, action });
  };

  const columns: Column<PurchaseRequest>[] = [
    {
      key: 'number',
      header: 'Number',
      sortable: true,
      width: '10rem',
      render: (row) => <span className="font-mono text-xs">{row.number}</span>,
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse?.name ?? '-'}</span>
      ),
    },
    {
      key: 'request_date',
      header: 'Request date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.request_date)}</span>
      ),
    },
    {
      key: 'required_date',
      header: 'Required date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.required_date)}</span>
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
              <>
                <IconButton
                  icon="checkmark-done-outline"
                  label="Approve request"
                  disabled={busy}
                  onClick={() => runTransition(row, 'approve')}
                />
                <IconButton
                  icon="close-circle-outline"
                  label="Reject request"
                  disabled={busy}
                  className="hover:text-danger"
                  onClick={() => {
                    transition.reset();
                    setRejectTarget(row);
                  }}
                />
              </>
            )}
            {status === 'approved' && can('purchases.create') && (
              <IconButton
                icon="arrow-redo-outline"
                label="Convert to purchase order"
                disabled={busy}
                onClick={() => runTransition(row, 'convert')}
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
    ? list.error.message || 'Failed to load purchase requests'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Purchase requests"
        description="Internal restock requests, from draft through approval to a purchase order."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon="add-outline"
              onClick={openCreate}
              disabled={companyId === null}
            >
              New request
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="pr_status_filter"
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
          name="pr_warehouse_filter"
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
        searchPlaceholder="Search request numbers..."
        emptyTitle="No purchase requests yet"
        emptyDescription="Raise a purchase request so a warehouse can ask for restock."
        emptyAction={
          canCreate
            ? { label: 'New request', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <PurchaseRequestFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={rejectTarget !== null}
        onClose={() => setRejectTarget(null)}
        onConfirm={() =>
          rejectTarget && transition.mutate({ id: rejectTarget.id, action: 'reject' })
        }
        title="Reject purchase request"
        message={
          <>
            Reject <strong className="text-text">{rejectTarget?.number}</strong>? A
            rejected request stays on record but cannot be converted.
          </>
        }
        confirmLabel="Reject request"
        variant="danger"
        loading={transition.isPending}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete purchase request"
        message={
          <>
            Delete <strong className="text-text">{removeTarget?.number}</strong>? This
            removes the draft and its lines; the action cannot be undone.
          </>
        }
        confirmLabel="Delete request"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
