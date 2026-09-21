import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { attributeApi } from '@/api/services';
import type { Attribute } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { formatDate } from '@/utils/format';
import { AttributeFormDrawer } from './attribute-form-drawer';

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

export default function AttributesPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Attribute>(
    listQueryKeys.attributes,
    (params) => attributeApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Attribute | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Attribute | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => attributeApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Attribute deleted', variant: 'success' });
      invalidate(listQueryKeys.attributes);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete attribute',
        message: apiErrorMessage(error, 'Failed to delete attribute'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (attribute: Attribute) => {
    setEditTarget(attribute);
    setFormOpen(true);
  };

  const openRemove = (attribute: Attribute) => {
    removeMutation.reset();
    setRemoveTarget(attribute);
  };

  const columns: Column<Attribute>[] = [
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
        <span className="font-medium text-text">{row.name}</span>
      ),
    },
    {
      key: 'data_type',
      header: 'Data type',
      render: (row) => (
        <span className="text-text-muted">
          {row.data_type ? row.data_type.charAt(0).toUpperCase() + row.data_type.slice(1) : '-'}
        </span>
      ),
    },
    {
      key: 'is_required',
      header: 'Required',
      render: (row) =>
        row.is_required ? (
          <Badge variant="warning" icon="asterisk">
            Required
          </Badge>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'is_filterable',
      header: 'Filterable',
      render: (row) =>
        row.is_filterable ? (
          <Badge variant="info" icon="funnel-outline">
            Filterable
          </Badge>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'values',
      header: 'Values',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">{row.values?.length ?? 0}</span>
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
          {can('attributes.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('attributes.delete') && (
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

  const canCreate = can('attributes.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load attributes'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Attributes"
        description="Product attributes such as colour or size, used to build variants."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New attribute
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
        searchPlaceholder="Search attributes..."
        emptyTitle="No attributes yet"
        emptyDescription="Add an attribute to describe and filter product variants."
        emptyAction={
          canCreate
            ? { label: 'New attribute', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <AttributeFormDrawer
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
        title="Delete attribute"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Variants
            using it will lose that value.
          </>
        }
        confirmLabel="Delete attribute"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
