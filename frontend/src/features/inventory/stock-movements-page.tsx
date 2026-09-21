import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { inventoryReportApi, warehouseApi } from '@/api/services';
import type { MovementType, StockMovement } from '@/types';
import { useListQuery } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Input, Select } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';
import {
  formatDate,
  formatDecimal,
  formatMoneyString,
  labelFor,
} from '@/utils/format';

const MOVEMENT_TYPES: MovementType[] = [
  'opening',
  'purchase',
  'purchase_return',
  'sale',
  'sale_return',
  'transfer_in',
  'transfer_out',
  'adjustment_in',
  'adjustment_out',
  'production_in',
  'production_out',
  'consumption',
  'stock_opname',
];

const movementTypeOptions = MOVEMENT_TYPES.map((value) => ({
  value,
  label: labelFor.movementType(value),
}));

/**
 * The reference column carries a polymorphic class name (e.g.
 * "App\Models\PurchaseOrder"); the bare basename plus id is what a reader needs.
 */
function referenceLabel(row: StockMovement): string {
  if (!row.reference_type) {
    return '-';
  }

  const model = row.reference_type.split('\\').pop() ?? row.reference_type;

  return row.reference_id ? `${model} #${row.reference_id}` : model;
}

/**
 * The ledger signs quantity: positive movements are stock in, negative ones are
 * stock out. Splitting the value into an In and an Out column keeps the sign
 * visible without summing the page (§46, §43).
 */
function movementQuantity(row: StockMovement): number {
  const value = Number(row.quantity);

  return Number.isFinite(value) ? value : 0;
}

export default function StockMovementsPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);

  const list = useListQuery<StockMovement>(
    listQueryKeys.stockMovements,
    (params) => inventoryReportApi.stockMovements(params),
    { company_id: companyId ?? undefined }
  );

  // Keep the ledger in sync when the active company changes in the topbar.
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

  const columns: Column<StockMovement>[] = [
    {
      key: 'occurred_at',
      header: 'Date',
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.occurred_at, true)}</span>
      ),
    },
    {
      key: 'reference',
      header: 'Reference',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">{referenceLabel(row)}</span>
      ),
    },
    {
      key: 'product',
      header: 'Product',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product?.name ?? '-'}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.product_variant_id
              ? `variant · ${row.product?.sku ?? ''}`
              : (row.product?.sku ?? '-')}
          </span>
        </div>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">{row.warehouse?.name ?? '-'}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.warehouse?.code ?? '-'}
          </span>
        </div>
      ),
    },
    {
      key: 'movement_type',
      header: 'Type',
      render: (row) => (
        <span className="text-text-muted">
          {labelFor.movementType(row.movement_type)}
        </span>
      ),
    },
    {
      key: 'quantity_in',
      header: 'In',
      align: 'right',
      render: (row) => {
        const quantity = movementQuantity(row);

        return quantity > 0 ? (
          <span className="tabular-nums font-medium text-success">
            {formatDecimal(quantity, 6)}
          </span>
        ) : (
          <span className="text-text-subtle">-</span>
        );
      },
    },
    {
      key: 'quantity_out',
      header: 'Out',
      align: 'right',
      render: (row) => {
        const quantity = movementQuantity(row);

        return quantity < 0 ? (
          <span className="tabular-nums font-medium text-danger">
            {formatDecimal(Math.abs(quantity), 6)}
          </span>
        ) : (
          <span className="text-text-subtle">-</span>
        );
      },
    },
    {
      key: 'balance_after',
      header: 'Balance',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text">
          {formatDecimal(row.balance_after, 6)}
        </span>
      ),
    },
    {
      key: 'unit_cost',
      header: 'Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.unit_cost)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? list.error.message || 'Failed to load stock movements'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Stock movements"
        description="The ledger of every quantity change, newest first. Movements are written by receipts, transfers, adjustments and sales."
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

        <Select
          label="Movement type"
          name="movement_type_filter"
          options={movementTypeOptions}
          placeholder="All types"
          value={list.params.movement_type ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              movement_type: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
        />

        <Input
          type="date"
          label="From"
          name="date_from"
          value={list.params.date_from ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_from: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9rem]"
        />

        <Input
          type="date"
          label="To"
          name="date_to"
          value={list.params.date_to ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_to: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[9rem]"
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
        searchPlaceholder="Search product or SKU..."
        emptyTitle="No stock movements"
        emptyDescription="The ledger is empty until a purchase receipt, transfer or adjustment posts a quantity change."
      />
    </div>
  );
}
