import { useState } from 'react';
import { auditApi } from '@/api/services';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardBody } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import { IconButton, Modal } from '@/components/ui/overlay';
import { PageHeader } from '@/components/ui/state';
import { formatDate } from '@/utils/format';
import type { AuditLog } from '@/types';

type BadgeVariant = 'default' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'outline';

const ACTION_OPTIONS = [
  { label: 'All actions', value: '' },
  { label: 'Login', value: 'login' },
  { label: 'Logout', value: 'logout' },
  { label: 'Password change', value: 'password.change' },
  { label: 'Password reset', value: 'password.reset' },
  { label: 'User created', value: 'user.create' },
  { label: 'User updated', value: 'user.update' },
  { label: 'User deleted', value: 'user.delete' },
  { label: 'Company created', value: 'company.create' },
  { label: 'Company updated', value: 'company.update' },
  { label: 'Company deleted', value: 'company.delete' },
  { label: 'Branch created', value: 'branch.create' },
  { label: 'Branch updated', value: 'branch.update' },
  { label: 'Branch deleted', value: 'branch.delete' },
  { label: 'Warehouse created', value: 'warehouse.create' },
  { label: 'Warehouse updated', value: 'warehouse.update' },
  { label: 'Warehouse deleted', value: 'warehouse.delete' },
  { label: 'Register created', value: 'register.create' },
  { label: 'Register updated', value: 'register.update' },
  { label: 'Register deleted', value: 'register.delete' },
  { label: 'Role created', value: 'role.create' },
  { label: 'Role updated', value: 'role.update' },
  { label: 'Role deleted', value: 'role.delete' },
  { label: 'Settings updated', value: 'settings.update' },
];

const ENTITY_OPTIONS = [
  { label: 'All entities', value: '' },
  { label: 'User', value: 'user' },
  { label: 'Company', value: 'company' },
  { label: 'Branch', value: 'branch' },
  { label: 'Warehouse', value: 'warehouse' },
  { label: 'Register', value: 'register' },
  { label: 'Role', value: 'role' },
  { label: 'Setting', value: 'setting' },
];

function actionVariant(action: string): BadgeVariant {
  const segment = action.split('.').pop() ?? action;

  if (segment === 'create') {
    return 'success';
  }

  if (segment === 'update') {
    return 'warning';
  }

  if (segment === 'delete') {
    return 'danger';
  }

  // login / logout / password.* are authentication activity.
  return 'info';
}

function JsonBlock({ label, value }: { label: string; value: unknown }) {
  return (
    <div className="flex flex-col gap-1.5">
      <p className="text-xs font-medium text-text-muted">{label}</p>
      {value === null || value === undefined ? (
        <p className="text-sm text-text-subtle">None</p>
      ) : (
        <pre className="overflow-x-auto rounded-md bg-surface-alt p-3 font-mono text-xs text-text">
          {JSON.stringify(value, null, 2)}
        </pre>
      )}
    </div>
  );
}

export default function AuditLogsPage() {
  const list = useListQuery<AuditLog>(
    listQueryKeys.auditLogs,
    (params) => auditApi.list(params),
    { per_page: 25 }
  );

  const [selected, setSelected] = useState<AuditLog | null>(null);

  const columns: Column<AuditLog>[] = [
    {
      key: 'created_at',
      header: 'Timestamp',
      render: (row) => (
        <span className="text-text-muted">
          {formatDate(row.created_at, true)}
        </span>
      ),
    },
    {
      key: 'user',
      header: 'User',
      render: (row) =>
        row.user ? (
          <div className="flex flex-col">
            <span className="font-medium text-text">{row.user.name}</span>
            <span className="text-xs text-text-subtle">{row.user.email}</span>
          </div>
        ) : (
          <span className="text-text-subtle">System</span>
        ),
    },
    {
      key: 'action',
      header: 'Action',
      render: (row) => <Badge variant={actionVariant(row.action)}>{row.action}</Badge>,
    },
    {
      key: 'entity',
      header: 'Entity',
      render: (row) => {
        if (!row.entity_type) {
          return <span className="text-text-subtle">-</span>;
        }

        return (
          <span className="text-text-muted">
            {row.entity_type}
            {row.entity_id !== null ? ` #${row.entity_id}` : ''}
          </span>
        );
      },
    },
    {
      key: 'ip_address',
      header: 'IP address',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">
          {row.ip_address ?? '-'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end">
          <IconButton
            icon="eye-outline"
            label="View details"
            onClick={() => setSelected(row)}
          />
        </div>
      ),
    },
  ];

  const hasFilters =
    !!(
      list.params.action ||
      list.params.entity_type ||
      list.params.start_date ||
      list.params.end_date
    );

  const errorMessage = list.isError
    ? (list.error?.message ?? 'Failed to load audit logs')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Audit Logs"
        description="A read-only trail of activity across the business."
      />

      <Card>
        <CardBody className="flex flex-wrap items-end gap-3 p-3">
          <Select
            label="Action"
            wrapperClassName="w-48"
            options={ACTION_OPTIONS}
            value={list.params.action ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                action: event.target.value || undefined,
                page: 1,
              })
            }
          />

          <Select
            label="Entity"
            wrapperClassName="w-40"
            options={ENTITY_OPTIONS}
            value={list.params.entity_type ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                entity_type: event.target.value || undefined,
                page: 1,
              })
            }
          />

          <Input
            name="start_date"
            label="From"
            type="date"
            wrapperClassName="w-44"
            value={list.params.start_date ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                start_date: event.target.value || undefined,
                page: 1,
              })
            }
          />

          <Input
            name="end_date"
            label="To"
            type="date"
            wrapperClassName="w-44"
            value={list.params.end_date ?? ''}
            onChange={(event) =>
              list.onParamsChange({
                end_date: event.target.value || undefined,
                page: 1,
              })
            }
          />

          <Button
            variant="secondary"
            size="sm"
            icon="close-circle-outline"
            disabled={!hasFilters}
            onClick={() =>
              list.onParamsChange({
                action: undefined,
                entity_type: undefined,
                start_date: undefined,
                end_date: undefined,
                page: 1,
              })
            }
          >
            Clear filters
          </Button>
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
        searchable={false}
        emptyTitle="No activity recorded"
        emptyDescription="Actions performed in this business will appear here."
      />

      <Modal
        open={!!selected}
        onClose={() => setSelected(null)}
        size="lg"
        title="Log entry"
        description={selected ? `${selected.action} — ${formatDate(selected.created_at, true)}` : undefined}
      >
        {selected && (
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-text-muted">User</span>
                <span className="text-sm text-text">
                  {selected.user?.name ?? 'System'}
                </span>
              </div>

              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-text-muted">
                  IP address
                </span>
                <span className="font-mono text-sm text-text">
                  {selected.ip_address ?? '-'}
                </span>
              </div>

              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-text-muted">
                  Entity
                </span>
                <span className="text-sm text-text">
                  {selected.entity_type
                    ? `${selected.entity_type}${selected.entity_id !== null ? ` #${selected.entity_id}` : ''}`
                    : '-'}
                </span>
              </div>

              <div className="flex flex-col gap-1">
                <span className="text-xs font-medium text-text-muted">
                  User agent
                </span>
                <span className="truncate text-sm text-text-muted" title={selected.user_agent ?? undefined}>
                  {selected.user_agent ?? '-'}
                </span>
              </div>
            </div>

            <JsonBlock label="Previous values" value={selected.old_values} />
            <JsonBlock label="New values" value={selected.new_values} />
          </div>
        )}
      </Modal>
    </div>
  );
}
