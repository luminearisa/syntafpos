import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { brandApi } from '@/api/services';
import type { Brand } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { StatusBadge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { formatDate } from '@/utils/format';
import { BrandFormDrawer } from './brand-form-drawer';

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

export default function BrandsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Brand>(
    listQueryKeys.brands,
    (params) => brandApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Brand | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Brand | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => brandApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Brand deleted', variant: 'success' });
      invalidate(listQueryKeys.brands);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete brand',
        message: apiErrorMessage(error, 'Failed to delete brand'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (brand: Brand) => {
    setEditTarget(brand);
    setFormOpen(true);
  };

  const openRemove = (brand: Brand) => {
    removeMutation.reset();
    setRemoveTarget(brand);
  };

  const columns: Column<Brand>[] = [
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
      key: 'products_count',
      header: 'Products',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">{row.products_count ?? 0}</span>
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
          {can('brands.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('brands.delete') && (
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

  const canCreate = can('brands.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load brands'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Brands"
        description="Group products by manufacturer or brand for filtering and reporting."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New brand
            </Button>
          ) : undefined
        }
      />

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
        searchPlaceholder="Search brands..."
        emptyTitle="No brands yet"
        emptyDescription="Add a brand so products can be grouped by manufacturer."
        emptyAction={
          canCreate
            ? { label: 'New brand', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <BrandFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        title="Delete brand"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Delete brand"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
