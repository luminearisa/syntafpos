import { type ReactNode, useState } from 'react';
import { exportApi } from '@/api/services';
import { api } from '@/api/client';
import type { ListParams } from '@/types';
import { cn } from '@/utils/format';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/ui/state';

/**
 * Shared read-only report primitives (spec §32/§33/§35).
 *
 * Reports are not CRUD resources: they render exactly what the API answered and
 * never fall back to a demo figure (§51). Money and quantities are always
 * routed through the formatters (§46).
 */

/* ------------------------------ CSV export ------------------------------ */

/**
 * The export endpoint is protected by the bearer token the API client attaches
 * via its request interceptor, so a plain anchor cannot carry it. The file is
 * fetched as a blob through the authenticated instance and turned into an
 * object URL the browser can download.
 */
export function useCsvExport() {
  const { toast } = useToast();
  const [busy, setBusy] = useState<string | null>(null);

  const exportCsv = async (entity: string, params: ListParams, fileName: string) => {
    setBusy(entity);
    try {
      const url = exportApi.url(entity, params);
      const response = await api.get<Blob>(url, {
        responseType: 'blob',
        // `exportApi.url` already carries the /api/v1 prefix.
        baseURL: '',
      });

      const objectUrl = URL.createObjectURL(response.data);
      const anchor = document.createElement('a');
      anchor.href = objectUrl;
      anchor.download = fileName;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(objectUrl);

      toast({ title: 'Export ready', message: fileName, variant: 'success' });
    } catch {
      toast({
        title: 'Export failed',
        message: 'The CSV could not be generated. Please try again.',
        variant: 'error',
      });
    } finally {
      setBusy(null);
    }
  };

  return { exportCsv, busy };
}

export function CsvExportButton({
  entity,
  params,
  fileName,
  label = 'Export CSV',
}: {
  entity: string;
  params: ListParams;
  fileName: string;
  label?: string;
}) {
  const { exportCsv, busy } = useCsvExport();

  return (
    <Button
      variant="outline"
      size="sm"
      icon="download-outline"
      loading={busy === entity}
      onClick={() => exportCsv(entity, params, fileName)}
    >
      {label}
    </Button>
  );
}

/* -------------------------------- Tabs ---------------------------------- */

export interface TabItem<Key extends string> {
  key: Key;
  label: string;
}

export function Tabs<Key extends string>({
  tabs,
  active,
  onChange,
}: {
  tabs: readonly TabItem<Key>[];
  active: Key;
  onChange: (key: Key) => void;
}) {
  return (
    <div
      role="tablist"
      className="flex flex-wrap gap-1 overflow-x-auto border-b border-border"
    >
      {tabs.map((tab) => {
        const isActive = tab.key === active;

        return (
          <button
            key={tab.key}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => onChange(tab.key)}
            className={cn(
              'shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'border-primary text-text'
                : 'border-transparent text-text-muted hover:text-text'
            )}
          >
            {tab.label}
          </button>
        );
      })}
    </div>
  );
}

/* --------------------- Table for non-paginated groups ------------------- */

export interface GroupColumn<T> {
  key: string;
  header: ReactNode;
  render: (row: T) => ReactNode;
  align?: 'left' | 'right' | 'center';
  width?: string;
  /** Optional content for the summary row's cell in this column. */
  footer?: ReactNode;
}

const groupAlignClasses = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

/**
 * Renders an ungrouped aggregation result (a plain array, not a page).
 *
 * Loading and error reuse the shared state components; an empty answer shows a
 * real "no data in this period" state rather than a fabricated total (§51).
 */
export function GroupTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  onRetry,
  emptyTitle = 'No data in this period',
  emptyDescription = 'Nothing matched the selected filters. Widen the range or clear a filter and run the report again.',
  showFooter = false,
}: {
  columns: GroupColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  /** Render the footer row when the response carries summary values. */
  showFooter?: boolean;
}) {
  if (loading) {
    return (
      <div className="rounded-lg border border-border bg-surface">
        <TableSkeleton rows={6} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg border border-border bg-surface">
        <ErrorState message={error} onRetry={onRetry} />
      </div>
    );
  }

  const hasRows = rows.length > 0;

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-border bg-surface-alt">
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                style={column.width ? { width: column.width } : undefined}
                className={cn(
                  'whitespace-nowrap px-3 py-2 text-xs font-semibold text-text-muted',
                  groupAlignClasses[column.align ?? 'left']
                )}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {hasRows &&
            rows.map((row) => (
              <tr
                key={rowKey(row)}
                className="border-b border-border last:border-0 hover:bg-surface-alt"
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={cn(
                      'whitespace-nowrap px-3 py-2 text-text',
                      groupAlignClasses[column.align ?? 'left']
                    )}
                  >
                    {column.render(row)}
                  </td>
                ))}
              </tr>
            ))}
        </tbody>

        {showFooter && (
          <tfoot>
            <tr className="border-t-2 border-border bg-surface-alt">
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={cn(
                    'whitespace-nowrap px-3 py-2 text-sm font-semibold text-text',
                    groupAlignClasses[column.align ?? 'left']
                  )}
                >
                  {column.footer ?? null}
                </td>
              ))}
            </tr>
          </tfoot>
        )}
      </table>

      {!hasRows && (
        <EmptyState
          icon="bar-chart-outline"
          title={emptyTitle}
          description={emptyDescription}
        />
      )}
    </div>
  );
}

/** Standard "no permission" state for a guarded report page. */
export function ForbiddenState({ permission }: { permission: string }) {
  return (
    <div className="flex flex-col gap-4">
      <div className="rounded-lg border border-border bg-surface">
        <EmptyState
          icon="lock-closed-outline"
          title="You do not have access to this report"
          description={`The ${permission} permission is required to view this page. Ask an administrator to grant it if you need it.`}
        />
      </div>
    </div>
  );
}
