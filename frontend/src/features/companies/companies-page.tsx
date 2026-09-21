import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { companyApi } from '@/api/services';
import type { Company } from '@/types';
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
import { CompanyFormDrawer } from './company-form-drawer';

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

export default function CompaniesPage() {
  const can = useAuthStore((state) => state.can);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Company>(
    listQueryKeys.companies,
    (params) => companyApi.list(params),
    { sort: 'created_at', direction: 'desc' }
  );

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Company | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Company | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => companyApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Company deleted', variant: 'success' });
      invalidate(listQueryKeys.companies);
      setRemoveTarget(null);
    },
    onError: (error) => {
      // The backend guards deletions (e.g. a company with branches) and
      // reports the reason in the 422 payload.
      toast({
        title: 'Could not delete company',
        message: apiErrorMessage(error, 'Failed to delete company'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (company: Company) => {
    setEditTarget(company);
    setFormOpen(true);
  };

  const openRemove = (company: Company) => {
    removeMutation.reset();
    setRemoveTarget(company);
  };

  const columns: Column<Company>[] = [
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
          {row.legal_name && (
            <span className="text-xs text-text-subtle">{row.legal_name}</span>
          )}
        </div>
      ),
    },
    {
      key: 'city',
      header: 'City',
      render: (row) => (
        <span className="text-text-muted">{row.city || '-'}</span>
      ),
    },
    {
      key: 'branches',
      header: 'Branches',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">{row.branches_count ?? 0}</span>
      ),
    },
    {
      key: 'currency',
      header: 'Currency',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">{row.currency}</span>
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
          {can('companies.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('companies.delete') && (
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

  const canCreate = can('companies.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load companies'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Companies"
        description="Manage the business entities available in this installation."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon="add-outline"
              onClick={openCreate}
            >
              New company
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
        searchPlaceholder="Search companies..."
        emptyTitle="No companies yet"
        emptyDescription="Create your first company to start setting up branches and warehouses."
        emptyAction={
          canCreate
            ? { label: 'New company', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <CompanyFormDrawer
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
        title="Delete company"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Delete company"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
