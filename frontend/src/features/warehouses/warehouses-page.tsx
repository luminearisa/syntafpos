import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { branchApi, warehouseApi } from '@/api/services';
import type { Warehouse } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { StatusBadge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { formatDate, labelFor } from '@/utils/format';
import { WarehouseFormDrawer } from './warehouse-form-drawer';

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

export default function WarehousesPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const companies = useAuthStore((state) => state.user?.companies) ?? [];
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  // The list endpoint does not eager-load company, so resolve display names
  // from the companies already available on the authenticated user.
  const companyName = new Map<number, string>(
    companies.map((company) => [company.id, company.name])
  );

  const list = useListQuery<Warehouse>(
    listQueryKeys.warehouses,
    (params) => warehouseApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, branch_id: undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: branchOptionsData } = useQuery({
    queryKey: ['branches', 'filter-options', companyId],
    queryFn: () => branchApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const branchOptions = (branchOptionsData?.data ?? []).map((branch) => ({
    label: branch.name,
    value: branch.id,
  }));

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Warehouse | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Warehouse | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => warehouseApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Warehouse deleted', variant: 'success' });
      invalidate(listQueryKeys.warehouses);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete warehouse',
        message: apiErrorMessage(error, 'Failed to delete warehouse'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (warehouse: Warehouse) => {
    setEditTarget(warehouse);
    setFormOpen(true);
  };

  const openRemove = (warehouse: Warehouse) => {
    removeMutation.reset();
    setRemoveTarget(warehouse);
  };

  const columns: Column<Warehouse>[] = [
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      width: '9rem',
      render: (row) => <span className="font-mono text-xs">{row.code}</span>,
    },
    {
      key: 'name',
      header: 'Name',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.name}</span>
          {row.description && (
            <span className="max-w-xs truncate text-xs text-text-subtle">
              {row.description}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'company',
      header: 'Company',
      render: (row) => (
        <span className="text-text-muted">
          {companyName.get(row.company_id) ?? '-'}
        </span>
      ),
    },
    {
      key: 'branch',
      header: 'Branch',
      render: (row) => (
        <span className="text-text-muted">{row.branch?.name ?? '-'}</span>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">
          {labelFor.warehouseType(row.type)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusBadge status={row.status} />,
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
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {can('warehouses.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('warehouses.delete') && (
            <IconButton
              icon="trash-outline"
              label={`Delete ${row.name}`}
              className="hover:text-danger"
              onClick={() => openRemove(row)}
            />
          )}
        </div>
      ),
    },
  ];

  const canCreate = can('warehouses.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load warehouses'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Warehouses"
        description="Track storage locations for stock across branches."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New warehouse
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Branch"
          name="branch_filter"
          options={branchOptions}
          placeholder="All branches"
          value={list.params.branch_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              branch_id:
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
        searchPlaceholder="Search warehouses..."
        emptyTitle="No warehouses yet"
        emptyDescription="Add a warehouse to start tracking stock movements."
        emptyAction={
          canCreate
            ? { label: 'New warehouse', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <WarehouseFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() =>
          removeTarget && removeMutation.mutate(removeTarget.id)
        }
        title="Delete warehouse"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Delete warehouse"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
