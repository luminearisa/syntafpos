import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { branchApi } from '@/api/services';
import type { Branch } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { StatusBadge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { formatDate, labelFor } from '@/utils/format';
import { BranchFormDrawer } from './branch-form-drawer';

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

export default function BranchesPage() {
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

  const list = useListQuery<Branch>(
    listQueryKeys.branches,
    (params) => branchApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Branch | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Branch | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => branchApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Branch deleted', variant: 'success' });
      invalidate(listQueryKeys.branches);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete branch',
        message: apiErrorMessage(error, 'Failed to delete branch'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (branch: Branch) => {
    setEditTarget(branch);
    setFormOpen(true);
  };

  const openRemove = (branch: Branch) => {
    removeMutation.reset();
    setRemoveTarget(branch);
  };

  const columns: Column<Branch>[] = [
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
          {row.address && (
            <span className="text-xs text-text-subtle">{row.address}</span>
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
      key: 'type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">{labelFor.branchType(row.type)}</span>
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
          {can('branches.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('branches.delete') && (
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

  const canCreate = can('branches.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load branches'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Branches"
        description="Organise outlets, head offices and other locations per company."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New branch
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
        searchPlaceholder="Search branches..."
        emptyTitle="No branches yet"
        emptyDescription="Add a branch to start registering warehouses and POS registers."
        emptyAction={
          canCreate
            ? { label: 'New branch', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <BranchFormDrawer
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
        title="Delete branch"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Delete branch"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
