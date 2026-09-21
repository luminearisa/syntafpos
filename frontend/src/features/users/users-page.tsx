import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { userApi } from '@/api/services';
import { useInvalidateList, useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useSwitcherOptions } from '@/components/layout/context-switcher';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { Badge, StatusBadge } from '@/components/ui/badge';
import { Card, CardBody } from '@/components/ui/card';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import { formatDate, initials } from '@/utils/format';
import type { Role, User } from '@/types';
import { UserFormDrawer } from './user-form-drawer';

interface ApiFailure {
  message: string;
  errors: Record<string, string[]>;
}

// Validation rejections carry a generic envelope message, so prefer the first
// field-level message (e.g. the "administrator cannot be deleted" 422).
function extractApiFailure(error: unknown): ApiFailure {
  const fallback: ApiFailure = { message: 'Something went wrong', errors: {} };

  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const body = (error as { response?: { data?: Record<string, unknown> } })
      .response?.data;

    if (body) {
      const errors = (body['errors'] as Record<string, string[]>) ?? {};
      const values = Object.values(errors);
      const first = values.length > 0 ? (values[0]?.[0] ?? undefined) : undefined;

      return {
        message:
          first ??
          (typeof body['message'] === 'string' ? body['message'] : fallback.message),
        errors,
      };
    }
  }

  if (error instanceof Error) {
    return { message: error.message, errors: {} };
  }

  return fallback;
}

function RoleBadges({ roles }: { roles: Role[] }) {
  if (roles.length === 0) {
    return <span className="text-text-subtle">-</span>;
  }

  const visible = roles.slice(0, 2);
  const overflow = roles.length - visible.length;

  return (
    <div className="flex items-center gap-1">
      {visible.map((role) => (
        <Badge key={role.id} variant="default">
          {role.display_name || role.name}
        </Badge>
      ))}
      {overflow > 0 && <Badge variant="outline">+{overflow}</Badge>}
    </div>
  );
}

export default function UsersPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { companies } = useSwitcherOptions();
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<User>(
    listQueryKeys.users,
    (params) => userApi.list(params),
    { company_id: companyId ?? undefined }
  );

  // Keep the filter aligned with the active business scope; on the backend the
  // query param takes precedence over the X-Company-Id header.
  useEffect(() => {
    if ((list.params.company_id ?? null) !== (companyId ?? null)) {
      list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [createOpen, setCreateOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [deleting, setDeleting] = useState<User | null>(null);

  // The list only carries companies and roles, so the full grant set is fetched
  // before the edit drawer opens.
  const detailQuery = useQuery({
    queryKey: [...listQueryKeys.users, 'detail', editId],
    enabled: editId !== null,
    queryFn: () => userApi.show(editId as number),
  });

  useEffect(() => {
    if (detailQuery.isError) {
      toast({ title: 'Failed to load user details', variant: 'error' });
      setEditId(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detailQuery.isError]);

  const deleteMutation = useMutation({
    mutationFn: (id: number) => userApi.remove(id),
    onSuccess: () => {
      invalidate(listQueryKeys.users);
      toast({ title: 'User deleted', variant: 'success' });
      setDeleting(null);
    },
    onError: (error) => {
      const failure = extractApiFailure(error);
      toast({ title: failure.message, variant: 'error' });
    },
  });

  const companyOptions = [
    { label: 'All companies', value: '' },
    ...companies.map((company) => ({
      label: company.label,
      value: company.id,
    })),
  ];

  const statusOptions = [
    { label: 'All statuses', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Inactive', value: 'inactive' },
  ];

  const columns: Column<User>[] = [
    {
      key: 'name',
      header: 'User',
      sortable: true,
      render: (row) => (
        <div className="flex items-center gap-2.5">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary">
            {initials(row.name) || '?'}
          </span>
          <div className="min-w-0">
            <p className="font-medium text-text">{row.name}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      sortable: true,
      render: (row) => <span className="text-text-muted">{row.email}</span>,
    },
    {
      key: 'roles',
      header: 'Roles',
      render: (row) => <RoleBadges roles={row.roles ?? []} />,
    },
    {
      key: 'companies',
      header: 'Companies',
      render: (row) => {
        const first = row.companies?.[0];

        if (!first) {
          return <span className="text-text-subtle">-</span>;
        }

        const overflow = (row.companies ?? []).length - 1;

        return (
          <div className="flex items-center gap-1">
            <span className="text-text">{first.name}</span>
            {overflow > 0 && <Badge variant="outline">+{overflow}</Badge>}
          </div>
        );
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'last_login_at',
      header: 'Last login',
      render: (row) => (
        <span className="text-text-muted">
          {formatDate(row.last_login_at, true)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {can('users.update') && (
            <IconButton
              icon="create-outline"
              label="Edit user"
              onClick={() => setEditId(row.id)}
            />
          )}
          {can('users.delete') && (
            <IconButton
              icon="trash-outline"
              label="Delete user"
              onClick={() => setDeleting(row)}
            />
          )}
        </div>
      ),
    },
  ];

  const errorMessage = list.isError
    ? (list.error?.message ?? 'Failed to load users')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Users"
        description="Manage accounts, roles and business access."
        actions={
          can('users.create') ? (
            <Button
              variant="primary"
              icon="person-add-outline"
              onClick={() => setCreateOpen(true)}
            >
              New user
            </Button>
          ) : null
        }
      />

      <Card>
        <CardBody className="flex flex-wrap items-end gap-3 p-3">
          <Select
            label="Company"
            wrapperClassName="w-48"
            options={companyOptions}
            value={list.params.company_id ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                company_id: event.target.value
                  ? Number(event.target.value)
                  : undefined,
                page: 1,
              })
            }
          />

          <Select
            label="Status"
            wrapperClassName="w-40"
            options={statusOptions}
            value={list.params.status ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                status: event.target.value || undefined,
                page: 1,
              })
            }
          />
        </CardBody>
      </Card>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={() => list.refetch()}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search users..."
        emptyTitle="No users yet"
        emptyDescription="Create an account to grant someone access to this business."
        emptyAction={
          can('users.create')
            ? {
                label: 'New user',
                icon: 'person-add-outline',
                onClick: () => setCreateOpen(true),
              }
            : undefined
        }
      />

      <UserFormDrawer
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        initial={null}
      />

      <UserFormDrawer
        open={editId !== null && !!detailQuery.data}
        onClose={() => setEditId(null)}
        initial={detailQuery.data?.data ?? null}
      />

      <ConfirmModal
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        title="Delete user"
        confirmLabel="Delete user"
        loading={deleteMutation.isPending}
        message={
          <>
            <p className="font-medium text-text">{deleting?.name}</p>
            <p className="mt-1">
              This account will immediately lose access to the business and its
              data. This action cannot be undone.
            </p>
          </>
        }
      />
    </div>
  );
}
