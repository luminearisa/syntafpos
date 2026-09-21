import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { supplierApi } from '@/api/services';
import type { Supplier } from '@/types';
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
import { formatDate } from '@/utils/format';
import { SupplierFormDrawer } from './supplier-form-drawer';

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

export default function SuppliersPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Supplier>(
    listQueryKeys.suppliers,
    (params) => supplierApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      status: undefined,
      page: 1,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Supplier | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Supplier | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => supplierApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Supplier deleted', variant: 'success' });
      invalidate(listQueryKeys.suppliers);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete supplier',
        message: apiErrorMessage(error, 'Failed to delete supplier'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (supplier: Supplier) => {
    setEditTarget(supplier);
    setFormOpen(true);
  };

  const openRemove = (supplier: Supplier) => {
    removeMutation.reset();
    setRemoveTarget(supplier);
  };

  const columns: Column<Supplier>[] = [
    {
      key: 'supplier_code',
      header: 'Code',
      sortable: true,
      width: '9rem',
      render: (row) => (
        <span className="font-mono text-xs">{row.supplier_code}</span>
      ),
    },
    {
      key: 'name',
      header: 'Supplier',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.name}</span>
          {row.company_name && (
            <span className="max-w-xs truncate text-xs text-text-subtle">
              {row.company_name}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'contact_person',
      header: 'Contact',
      render: (row) => (
        <span className="text-text-muted">{row.contact_person ?? '-'}</span>
      ),
    },
    {
      key: 'phone',
      header: 'Phone',
      render: (row) => (
        <span className="text-text-muted">{row.phone ?? '-'}</span>
      ),
    },
    {
      key: 'city',
      header: 'City',
      render: (row) => (
        <span className="text-text-muted">{row.city ?? '-'}</span>
      ),
    },
    {
      key: 'payment_terms',
      header: 'Payment terms',
      render: (row) => (
        <span className="text-text-muted">{row.payment_terms ?? '-'}</span>
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
          {can('suppliers.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('suppliers.delete') && (
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

  const canCreate = can('suppliers.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load suppliers'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Suppliers"
        description="Suppliers for purchase orders, receipts and returns."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New supplier
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Status"
          name="supplier_status_filter"
          options={[
            { label: 'Active', value: 'active' },
            { label: 'Inactive', value: 'inactive' },
          ]}
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
        searchPlaceholder="Search suppliers by name, code or contact..."
        emptyTitle="No suppliers yet"
        emptyDescription="Add a supplier to start creating purchase orders."
        emptyAction={
          canCreate
            ? { label: 'New supplier', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <SupplierFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        title="Delete supplier"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Its purchase
            history is retained. This action cannot be undone.
          </>
        }
        confirmLabel="Delete supplier"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
