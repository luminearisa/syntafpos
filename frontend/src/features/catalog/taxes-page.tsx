import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { taxApi } from '@/api/services';
import type { Tax } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { formatDecimal, formatDate, labelFor } from '@/utils/format';
import { TaxFormDrawer } from './tax-form-drawer';

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

export default function TaxesPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Tax>(
    listQueryKeys.taxes,
    (params) => taxApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Tax | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Tax | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => taxApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Tax deleted', variant: 'success' });
      invalidate(listQueryKeys.taxes);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete tax',
        message: apiErrorMessage(error, 'Failed to delete tax'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (tax: Tax) => {
    setEditTarget(tax);
    setFormOpen(true);
  };

  const openRemove = (tax: Tax) => {
    removeMutation.reset();
    setRemoveTarget(tax);
  };

  const columns: Column<Tax>[] = [
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      width: '8rem',
      render: (row) => <span className="font-mono text-xs">{row.code}</span>,
    },
    {
      key: 'name',
      header: 'Name',
      sortable: true,
      render: (row) => (
        <span className="font-medium text-text">{row.name}</span>
      ),
    },
    {
      key: 'rate',
      header: 'Rate',
      align: 'right',
      render: (row) => (
        <span className="font-mono text-sm text-text">
          {formatDecimal(row.rate)}%
        </span>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">{labelFor.taxType(row.type)}</span>
      ),
    },
    {
      key: 'is_default',
      header: 'Default',
      render: (row) =>
        row.is_default ? (
          <Badge variant="primary" icon="star">
            Default
          </Badge>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'is_active',
      header: 'Active',
      render: (row) =>
        row.is_active ? (
          <Badge variant="success" icon="checkmark-circle">
            Active
          </Badge>
        ) : (
          <Badge variant="default" icon="ellipse-outline">
            Inactive
          </Badge>
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
          {can('taxes.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('taxes.delete') && (
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

  const canCreate = can('taxes.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load taxes'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Taxes"
        description="Tax rates and calculation modes for products and documents."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New tax
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
        searchPlaceholder="Search taxes..."
        emptyTitle="No taxes yet"
        emptyDescription="Add a tax rate so products and sales can be taxed correctly."
        emptyAction={
          canCreate
            ? { label: 'New tax', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <TaxFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        title="Delete tax"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Products
            using it will keep the reference until reassigned.
          </>
        }
        confirmLabel="Delete tax"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
