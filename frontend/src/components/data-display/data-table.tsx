import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { cn } from '@/utils/format';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/ui/state';
import { Input } from '@/components/ui/input';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import type { ListParams, PaginationMeta } from '@/types';

export interface Column<T> {
  key: string;
  header: ReactNode;
  render: (row: T) => ReactNode;
  sortable?: boolean;
  align?: 'left' | 'right' | 'center';
  className?: string;
  width?: string;
}

interface DataTableProps<T> {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;

  pagination?: PaginationMeta | null;
  params: ListParams;
  onParamsChange: (params: Partial<ListParams>) => void;

  searchable?: boolean;
  searchPlaceholder?: string;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: { label: string; onClick: () => void; icon?: string };
  stickyHeader?: boolean;
}

const alignClasses = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  onRetry,
  pagination,
  params,
  onParamsChange,
  searchable = true,
  searchPlaceholder = 'Search...',
  emptyTitle = 'No records found',
  emptyDescription = 'There is nothing to display yet.',
  emptyAction,
  stickyHeader = true,
}: DataTableProps<T>) {
  const totalPages = pagination?.last_page ?? 1;
  const currentPage = pagination?.current_page ?? params.page ?? 1;

  const [search, setSearch] = useState(params.search ?? '');
  const debouncedSearch = useDebouncedValue(search);

  useEffect(() => {
    if (debouncedSearch !== (params.search ?? '')) {
      onParamsChange({ search: debouncedSearch, page: 1 });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const sort = (key: string) => {
    if (!columns.some((column) => column.key === key && column.sortable)) {
      return;
    }

    const direction =
      params.sort === key && params.direction === 'asc' ? 'desc' : 'asc';

    onParamsChange({ sort: key, direction, page: 1 });
  };

  const perPageOptions = useMemo(
    () => [10, 20, 50, 100].map((value) => ({ label: `${value}`, value })),
    []
  );

  if (loading) {
    return (
      <div className="overflow-hidden rounded-xl border border-border/80 bg-surface shadow-xs">
        <TableSkeleton rows={6} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="overflow-hidden rounded-xl border border-border/80 bg-surface shadow-xs">
        <ErrorState message={error} onRetry={onRetry} />
      </div>
    );
  }

  const hasRows = rows.length > 0;

  return (
    <div className="flex flex-col gap-3">
      {searchable && (
        <div className="flex flex-col items-stretch justify-between gap-2.5 sm:flex-row sm:items-center">
          <Input
            name="search"
            icon="search-outline"
            placeholder={searchPlaceholder}
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            wrapperClassName="w-full sm:max-w-md"
            className="h-10"
          />
          {pagination && (
            <span className="shrink-0 text-xs text-text-muted">
              {pagination.total} record{pagination.total === 1 ? '' : 's'}
            </span>
          )}
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-border/80 bg-surface shadow-xs">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr
              className={cn(
                'border-b border-border/80 bg-surface-alt/80',
                stickyHeader && 'sticky top-0 z-10'
              )}
            >
              {columns.map((column) => {
                const isActive = params.sort === column.key;

                return (
                  <th
                    key={column.key}
                    scope="col"
                    style={column.width ? { width: column.width } : undefined}
                    className={cn(
                      'px-3.5 py-3 text-[10px] font-bold tracking-[0.08em] text-text-muted whitespace-nowrap uppercase',
                      alignClasses[column.align ?? 'left'],
                      column.className
                    )}
                  >
                    {column.sortable ? (
                      <button
                        type="button"
                        onClick={() => sort(column.key)}
                        className="inline-flex items-center gap-1 text-text-muted hover:text-text"
                      >
                        {column.header}
                        <ion-icon
                          name={
                            isActive
                              ? params.direction === 'asc'
                                ? 'arrow-up'
                                : 'arrow-down'
                              : 'swap-vertical-outline'
                          }
                          class={cn('text-[0.85em]', isActive && 'text-primary')}
                          aria-hidden="true"
                        />
                      </button>
                    ) : (
                      column.header
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>

          <tbody>
            {hasRows ? (
              rows.map((row) => (
                <tr
                  key={rowKey(row)}
                  className="border-b border-border/70 transition-colors last:border-0 hover:bg-primary-soft/35"
                >
                  {columns.map((column) => (
                    <td
                      key={column.key}
                      className={cn(
                        'px-3.5 py-2.5 text-[13px] text-text whitespace-nowrap',
                        alignClasses[column.align ?? 'left'],
                        column.className
                      )}
                    >
                      {column.render(row)}
                    </td>
                  ))}
                </tr>
              ))
            ) : null}
          </tbody>
        </table>

        {!hasRows && (
          <EmptyState
            title={emptyTitle}
            description={emptyDescription}
            action={emptyAction}
            icon="file-tray-outline"
          />
        )}
      </div>

      {pagination && hasRows && (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2 text-xs text-text-muted">
            <span>Show</span>
            <select
              aria-label="Rows per page"
              className="h-9 rounded-lg border border-border bg-surface px-2.5 text-xs font-medium text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15"
              value={params.per_page ?? 20}
              onChange={(event) =>
                onParamsChange({ per_page: Number(event.target.value), page: 1 })
              }
            >
              {perPageOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
            <span>
              {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total}
            </span>
          </div>

          <div className="flex items-center gap-1">
            <PaginationButton
              icon="chevron-back-outline"
              label="Previous page"
              disabled={currentPage <= 1}
              onClick={() => onParamsChange({ page: currentPage - 1 })}
            />

            <span className="px-2 text-xs text-text-muted">
              {currentPage} / {totalPages}
            </span>

            <PaginationButton
              icon="chevron-forward-outline"
              label="Next page"
              disabled={currentPage >= totalPages}
              onClick={() => onParamsChange({ page: currentPage + 1 })}
            />
          </div>
        </div>
      )}
    </div>
  );
}

function PaginationButton({
  icon,
  label,
  disabled,
  onClick,
}: {
  icon: string;
  label: string;
  disabled: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className={cn(
        'flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-surface text-text-muted shadow-xs transition-colors',
        'hover:bg-surface-alt hover:text-text',
        'disabled:cursor-not-allowed disabled:opacity-40',
        'focus-visible:outline-2 focus-visible:outline-primary'
      )}
    >
      <ion-icon name={icon} aria-hidden="true" />
    </button>
  );
}
