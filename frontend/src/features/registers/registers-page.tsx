import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { branchApi, registerApi } from '@/api/services';
import type { Register } from '@/types';
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
import { RegisterFormDrawer } from './register-form-drawer';
import { formatDate } from '@/utils/format';

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

export default function RegistersPage() {
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

  const list = useListQuery<Register>(
    listQueryKeys.registers,
    (params) => registerApi.list(params),
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
  const [editTarget, setEditTarget] = useState<Register | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Register | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => registerApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Register deleted', variant: 'success' });
      invalidate(listQueryKeys.registers);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete register',
        message: apiErrorMessage(error, 'Failed to delete register'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (register: Register) => {
    setEditTarget(register);
    setFormOpen(true);
  };

  const openRemove = (register: Register) => {
    removeMutation.reset();
    setRemoveTarget(register);
  };

  const columns: Column<Register>[] = [
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
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse?.name ?? '-'}</span>
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
          {can('registers.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('registers.delete') && (
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

  const canCreate = can('registers.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load registers'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Registers"
        description="Manage the point-of-sale registers available to your staff."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New register
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
        searchPlaceholder="Search registers..."
        emptyTitle="No registers yet"
        emptyDescription="Add a register to start recording sales sessions."
        emptyAction={
          canCreate
            ? { label: 'New register', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <RegisterFormDrawer
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
        title="Delete register"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Delete register"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
