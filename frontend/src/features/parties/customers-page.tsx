import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { customerApi } from '@/api/services';
import type { Customer } from '@/types';
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
import { formatDate } from '@/utils/format';
import { CustomerFormDrawer } from './customer-form-drawer';

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

export default function CustomersPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Customer>(
    listQueryKeys.customers,
    (params) => customerApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      type: undefined,
      status: undefined,
      page: 1,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Customer | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Customer | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => customerApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Customer deleted', variant: 'success' });
      invalidate(listQueryKeys.customers);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete customer',
        message: apiErrorMessage(error, 'Failed to delete customer'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (customer: Customer) => {
    setEditTarget(customer);
    setFormOpen(true);
  };

  const openRemove = (customer: Customer) => {
    removeMutation.reset();
    setRemoveTarget(customer);
  };

  const columns: Column<Customer>[] = [
    {
      key: 'customer_code',
      header: 'Code',
      sortable: true,
      width: '9rem',
      render: (row) => (
        <span className="font-mono text-xs">{row.customer_code}</span>
      ),
    },
    {
      key: 'name',
      header: 'Customer',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.name}</span>
          {row.customer_group && (
            <span className="text-xs text-text-subtle">
              {row.customer_group.name}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">
          {row.type === 'company'
            ? 'Company'
            : row.type === 'individual'
              ? 'Individual'
              : '-'}
        </span>
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
      key: 'is_active',
      header: 'Status',
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
          {can('customers.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('customers.delete') && (
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

  const canCreate = can('customers.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load customers'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Customers"
        description="Customer profiles for sales, pricing and credit control."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New customer
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Type"
          name="customer_type_filter"
          options={[
            { label: 'Individual', value: 'individual' },
            { label: 'Company', value: 'company' },
          ]}
          placeholder="All types"
          value={list.params.type ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              type: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
        />
        <Select
          label="Status"
          name="customer_status_filter"
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
        searchPlaceholder="Search customers by name, code or phone..."
        emptyTitle="No customers yet"
        emptyDescription="Add a customer to start recording sales and credit terms."
        emptyAction={
          canCreate
            ? { label: 'New customer', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <CustomerFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        title="Delete customer"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Their sales
            history is retained. This action cannot be undone.
          </>
        }
        confirmLabel="Delete customer"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
