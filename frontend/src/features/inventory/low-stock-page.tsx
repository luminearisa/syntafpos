import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { exportApi, inventoryReportApi, warehouseApi } from '@/api/services';
import type { LowStockRow } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import { formatDecimal } from '@/utils/format';

const LOW_STOCK_QUERY_KEY = ['reports', 'inventory', 'low-stock'] as const;

export default function LowStockPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const canView = useAuthStore((state) => state.can('inventory.view'));

  const list = useListQuery<LowStockRow>(
    LOW_STOCK_QUERY_KEY,
    (params) => inventoryReportApi.lowStock(params),
    { company_id: companyId ?? undefined }
  );

  // Keep the report in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({
      company_id: companyId ?? undefined,
      warehouse_id: undefined,
      page: 1,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'filter-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const columns: Column<LowStockRow>[] = [
    {
      key: 'product_name',
      header: 'Product',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          <span className="font-mono text-xs text-text-subtle">{row.product_sku}</span>
        </div>
      ),
    },
    {
      key: 'product_sku',
      header: 'SKU',
      width: '9rem',
      render: (row) => <span className="font-mono text-xs">{row.product_sku}</span>,
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{row.warehouse_name}</span>
          <span className="font-mono text-xs text-text-subtle">{row.warehouse_code}</span>
        </div>
      ),
    },
    {
      key: 'on_hand',
      header: 'On hand',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text">{formatDecimal(row.on_hand, 6)}</span>
      ),
    },
    {
      key: 'reserved',
      header: 'Reserved',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.reserved, 6)}
        </span>
      ),
    },
    {
      key: 'available',
      header: 'Available',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums font-medium text-text">
          {formatDecimal(row.available, 6)}
        </span>
      ),
    },
    {
      key: 'reorder_point',
      header: 'Reorder point',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.reorder_point, 6)}
        </span>
      ),
    },
    {
      key: 'suggested_reorder_quantity',
      header: 'Suggested reorder',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums font-medium text-primary">
          {formatDecimal(row.suggested_reorder_quantity, 6)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? list.error.message || 'Failed to load the low-stock report'
    : null;

  // The export honours the very filters the table shows, so the CSV matches the
  // rows on screen.
  const exportUrl = exportApi.url('stock', {
    company_id: companyId ?? undefined,
    warehouse_id: list.params.warehouse_id,
    search: list.params.search,
  });

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Low stock"
        description="Inventory-tracked products whose available quantity has reached the reorder point, with the quantity suggested to replenish."
        actions={
          canView && list.rows.length > 0 ? (
            <a
              href={exportUrl}
              className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-primary bg-transparent px-4 text-sm font-medium text-primary transition-colors hover:bg-primary-soft"
            >
              <ion-icon name="download-outline" class="text-[1.1em]" aria-hidden="true" />
              Export CSV
            </a>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <Select
          label="Warehouse"
          name="warehouse_filter"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              warehouse_id:
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
        rowKey={(row) => `${row.product_id}-${row.warehouse_id}-${row.unit_code}`}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search product or SKU..."
        emptyTitle="Nothing below the reorder point"
        emptyDescription="Every tracked product holds more than its reorder point. Products stop being tracked once restocked above it."
      />
    </div>
  );
}
