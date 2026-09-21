import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { roleApi } from '@/api/services';
import { useInvalidateList, useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ConfirmModal, IconButton, Tooltip } from '@/components/ui/overlay';
import { PageHeader } from '@/components/ui/state';
import { useToast } from '@/components/ui/toast';
import type { Role } from '@/types';
import { RoleFormDrawer } from './role-form-drawer';

interface ApiFailure {
  message: string;
  errors: Record<string, string[]>;
}

// Validation rejections carry a generic envelope message, so prefer the first
// field-level message (e.g. the "role still has users" 422).
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

export default function RolesPage() {
  const can = useAuthStore((state) => state.can);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Role>(
    listQueryKeys.roles,
    (params) => roleApi.list(params),
    { per_page: 50 }
  );

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Role | null>(null);
  const [deleting, setDeleting] = useState<Role | null>(null);

  const deleteMutation = useMutation({
    mutationFn: (id: number) => roleApi.remove(id),
    onSuccess: () => {
      invalidate(listQueryKeys.roles);
      toast({ title: 'Role deleted', variant: 'success' });
      setDeleting(null);
    },
    onError: (error) => {
      const failure = extractApiFailure(error);
      toast({ title: failure.message, variant: 'error' });
    },
  });

  const columns: Column<Role>[] = [
    {
      key: 'display_name',
      header: 'Role',
      render: (row) => (
        <div className="flex items-center gap-2">
          <span className="font-medium text-text">
            {row.display_name || row.name}
          </span>
          {row.is_system && <Badge variant="info">System</Badge>}
        </div>
      ),
    },
    {
      key: 'name',
      header: 'Name',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">{row.name}</span>
      ),
    },
    {
      key: 'description',
      header: 'Description',
      render: (row) =>
        row.description ? (
          <span className="line-clamp-1 max-w-xs text-text-muted">
            {row.description}
          </span>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'permissions',
      header: 'Permissions',
      render: (row) => (
        <Badge variant="default">{(row.permissions ?? []).length}</Badge>
      ),
    },
    {
      key: 'users_count',
      header: 'Users',
      render: (row) => (
        <span className="text-text-muted">{row.users_count ?? 0}</span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {can('roles.update') &&
            (row.is_system ? (
              <Tooltip content="System roles are read-only">
                <IconButton icon="create-outline" label="Edit role" disabled />
              </Tooltip>
            ) : (
              <IconButton
                icon="create-outline"
                label="Edit role"
                onClick={() => setEditing(row)}
              />
            ))}

          {can('roles.delete') &&
            (row.is_system ? (
              <Tooltip content="System roles are read-only">
                <IconButton icon="trash-outline" label="Delete role" disabled />
              </Tooltip>
            ) : (row.users_count ?? 0) > 0 ? (
              <Tooltip content="This role is still assigned to users. Reassign them first.">
                <IconButton icon="trash-outline" label="Delete role" disabled />
              </Tooltip>
            ) : (
              <IconButton
                icon="trash-outline"
                label="Delete role"
                onClick={() => setDeleting(row)}
              />
            ))}
        </div>
      ),
    },
  ];

  const errorMessage = list.isError
    ? (list.error?.message ?? 'Failed to load roles')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Roles & Permissions"
        description="Group permissions into reusable roles."
        actions={
          can('roles.create') ? (
            <Button
              variant="primary"
              icon="key-outline"
              onClick={() => setCreateOpen(true)}
            >
              New role
            </Button>
          ) : null
        }
      />

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
        searchPlaceholder="Search roles..."
        emptyTitle="No roles yet"
        emptyDescription="Create a role to bundle permissions you can assign to users."
        emptyAction={
          can('roles.create')
            ? {
                label: 'New role',
                icon: 'key-outline',
                onClick: () => setCreateOpen(true),
              }
            : undefined
        }
      />

      <RoleFormDrawer
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        initial={null}
      />

      <RoleFormDrawer
        open={!!editing}
        onClose={() => setEditing(null)}
        initial={editing}
      />

      <ConfirmModal
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        title="Delete role"
        confirmLabel="Delete role"
        loading={deleteMutation.isPending}
        message={
          <>
            <p className="font-medium text-text">
              {deleting?.display_name || deleting?.name}
            </p>
            <p className="mt-1">
              Users assigned to this role will lose the permissions it grants.
              This action cannot be undone.
            </p>
          </>
        }
      />
    </div>
  );
}
