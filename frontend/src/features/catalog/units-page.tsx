import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { unitApi } from '@/api/services';
import type { Unit } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { Badge, StatusBadge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { formatDecimal, formatDate, labelFor } from '@/utils/format';
import { UnitFormDrawer } from './unit-form-drawer';

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

export default function UnitsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<Unit>(
    listQueryKeys.units,
    (params) => unitApi.list(params),
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Unit | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Unit | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => unitApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Unit deleted', variant: 'success' });
      invalidate(listQueryKeys.units);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete unit',
        message: apiErrorMessage(error, 'Failed to delete unit'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (unit: Unit) => {
    setEditTarget(unit);
    setFormOpen(true);
  };

  const openRemove = (unit: Unit) => {
    removeMutation.reset();
    setRemoveTarget(unit);
  };

  const columns: Column<Unit>[] = [
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
      key: 'unit_type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">{labelFor.unitType(row.unit_type)}</span>
      ),
    },
    {
      key: 'is_base',
      header: 'Base',
      render: (row) =>
        row.is_base ? (
          <Badge variant="primary" icon="checkmark-circle">
            Base
          </Badge>
        ) : (
          <Badge variant="outline">Derived</Badge>
        ),
    },
    {
      key: 'base_factor',
      header: 'Conversion',
      render: (row) => {
        if (!row.base_unit_id || !row.base_factor) {
          return <span className="text-text-subtle">-</span>;
        }

        return (
          <span className="text-text-muted">
            1 = {formatDecimal(row.base_factor)}{' '}
            <span className="text-text-subtle">base</span>
          </span>
        );
      },
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
          {can('units.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('units.delete') && (
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

  const canCreate = can('units.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load units'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Units"
        description="Units of measure for purchasing, stock and sales quantities."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New unit
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Type"
          name="unit_type_filter"
          options={[
            { label: 'Piece', value: 'piece' },
            { label: 'Weight', value: 'weight' },
            { label: 'Volume', value: 'volume' },
            { label: 'Length', value: 'length' },
            { label: 'Other', value: 'other' },
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
        searchPlaceholder="Search units..."
        emptyTitle="No units yet"
        emptyDescription="Add a unit of measure before registering products."
        emptyAction={
          canCreate
            ? { label: 'New unit', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <UnitFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        title="Delete unit"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Products
            using it will keep their quantities until reassigned.
          </>
        }
        confirmLabel="Delete unit"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
