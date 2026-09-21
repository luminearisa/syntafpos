import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { categoryApi } from '@/api/services';
import type { Category } from '@/types';
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
import { CategoryFormDrawer } from './category-form-drawer';

interface FlatCategory extends Category {
  depth: number;
}

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

/**
 * Walk the nested category tree into the row-based list the DataTable needs,
 * recording how deep each node sits so the name can be indented.
 */
function flattenCategoryTree(
  nodes: Category[],
  depth = 0,
  acc: FlatCategory[] = []
): FlatCategory[] {
  nodes.forEach((node) => {
    acc.push({ ...node, depth });

    if (node.children && node.children.length > 0) {
      flattenCategoryTree(node.children, depth + 1, acc);
    }
  });

  return acc;
}

export default function CategoriesPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<FlatCategory>(
    listQueryKeys.categories,
    // The endpoint answers with a nested tree rather than a paginated envelope,
    // so flatten it here and report the whole set as one page.
    async (params) => {
      const response = await categoryApi.list(params);
      const flattened = flattenCategoryTree(response.data ?? []);

      return {
        ...response,
        data: flattened,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: flattened.length,
          total: flattened.length,
          from: flattened.length === 0 ? null : 1,
          to: flattened.length,
        },
      };
    },
    { company_id: companyId ?? undefined, sort: 'created_at', direction: 'desc' }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Category | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Category | null>(null);

  const removeMutation = useMutation({
    mutationFn: (id: number) => categoryApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Category deleted', variant: 'success' });
      invalidate(listQueryKeys.categories);
      setRemoveTarget(null);
    },
    onError: (error) => {
      toast({
        title: 'Could not delete category',
        message: apiErrorMessage(error, 'Failed to delete category'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (category: Category) => {
    setEditTarget(category);
    setFormOpen(true);
  };

  const openRemove = (category: Category) => {
    removeMutation.reset();
    setRemoveTarget(category);
  };

  const columns: Column<FlatCategory>[] = [
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
          <span
            className="font-medium text-text"
            style={{ paddingLeft: `${row.depth * 1.25}rem` }}
          >
            {row.depth > 0 ? '└ ' : ''}
            {row.name}
          </span>
          {row.description && (
            <span
              className="max-w-xs truncate text-xs text-text-subtle"
              style={{ paddingLeft: `${row.depth * 1.25}rem` }}
            >
              {row.description}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'children_count',
      header: 'Subcategories',
      align: 'right',
      render: (row) => (
        <span className="text-text-muted">{row.children_count ?? row.children?.length ?? 0}</span>
      ),
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
          {can('categories.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('categories.delete') && (
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

  const canCreate = can('categories.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load categories'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Categories"
        description="Organise products into a nested category tree for grouping and reporting."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New category
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
        searchPlaceholder="Search categories..."
        emptyTitle="No categories yet"
        emptyDescription="Add a category to start grouping your products."
        emptyAction={
          canCreate
            ? { label: 'New category', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <CategoryFormDrawer
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
        title="Delete category"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>? Its
            subcategories will move up one level. This action cannot be undone.
          </>
        }
        confirmLabel="Delete category"
        variant="danger"
        loading={removeMutation.isPending}
      />
    </div>
  );
}
