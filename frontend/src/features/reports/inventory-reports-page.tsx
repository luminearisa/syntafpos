import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { inventoryReportApi, productApi, warehouseApi } from '@/api/services';
import type {
  ApiResponse,
  ListParams,
  LowStockRow,
  Product,
  StockCardRow,
  StockSummaryRow,
  StockValuationRow,
} from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useListQuery } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/badge';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import {
  CsvExportButton,
  ForbiddenState,
  GroupTable,
  Tabs,
  type GroupColumn,
  type TabItem,
} from './report-helpers';
import {
  formatDate,
  formatDecimal,
  formatMoneyString,
  labelFor,
} from '@/utils/format';

/**
 * Inventory reports (spec §32): read-only aggregations over the stock ledger.
 *
 * Every figure shown is the API's own answer; an empty filter window renders a
 * real empty state, never a demo number (§51). Money and quantities are only
 * ever displayed through the formatters (§46).
 */

type InventoryTab =
  | 'summary'
  | 'card'
  | 'movements'
  | 'low-stock'
  | 'valuation'
  | 'opnames'
  | 'transfers';

const TABS: readonly TabItem<InventoryTab>[] = [
  { key: 'summary', label: 'Stock Summary' },
  { key: 'card', label: 'Stock Card' },
  { key: 'movements', label: 'Stock Movement' },
  { key: 'low-stock', label: 'Low Stock' },
  { key: 'valuation', label: 'Stock Valuation' },
  { key: 'opnames', label: 'Stock Opname' },
  { key: 'transfers', label: 'Warehouse Transfer' },
];

const MOVEMENT_TYPE_OPTIONS = [
  'opening',
  'purchase',
  'sale',
  'sale_return',
  'transfer_in',
  'transfer_out',
  'adjustment_in',
  'adjustment_out',
  'production_in',
  'production_out',
  'stock_opname',
  'return_to_supplier',
].map((value) => ({ label: labelFor.movementType(value), value }));

const REFERENCE_OPTIONS = [
  'App\\Models\\GoodsReceipt',
  'App\\Models\\PurchaseReturn',
  'App\\Models\\StockAdjustment',
  'App\\Models\\StockOpname',
  'App\\Models\\WarehouseTransfer',
].map((value) => ({
  label: labelFor.documentStatus(value.split('\\').pop() ?? value),
  value,
}));

function reportErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string } };
    };

    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }
  }

  if (error instanceof Error) {
    return error.message;
  }

  return fallback;
}

export default function InventoryReportsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const [tab, setTab] = useState<InventoryTab>('summary');

  if (!can('reports.inventory')) {
    return <ForbiddenState permission="reports.inventory" />;
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Inventory Reports"
        description="Stock position, ledger movements, replenishment and valuation across your warehouses."
      />

      <Tabs tabs={TABS} active={tab} onChange={setTab} />

      {tab === 'summary' && <StockSummaryTab companyId={companyId} />}
      {tab === 'card' && <StockCardTab companyId={companyId} />}
      {tab === 'movements' && <StockMovementsTab companyId={companyId} />}
      {tab === 'low-stock' && <LowStockTab companyId={companyId} />}
      {tab === 'valuation' && <StockValuationTab companyId={companyId} />}
      {tab === 'opnames' && <StockOpnamesTab companyId={companyId} />}
      {tab === 'transfers' && <WarehouseTransfersTab companyId={companyId} />}
    </div>
  );
}

/* ---------------------------- Filter helpers ---------------------------- */

function useWarehouseOptions(companyId: number | null) {
  const { data } = useQuery({
    queryKey: [...listQueryKeys.warehouses, 'report-filter', companyId],
    queryFn: () =>
      warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });

  return (data?.data ?? []).map((warehouse) => ({
    label: `${warehouse.code} — ${warehouse.name}`,
    value: warehouse.id,
  }));
}

function useProductOptions(companyId: number | null, search: string) {
  const { data } = useQuery({
    queryKey: [...listQueryKeys.products, 'report-filter', companyId, search],
    queryFn: () =>
      productApi.list({
        company_id: companyId ?? undefined,
        search: search || undefined,
        per_page: 50,
      }),
    enabled: companyId !== null,
  });

  return data?.data ?? [];
}

/* ----------------------------- Stock summary ---------------------------- */

function StockSummaryTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);

  const list = useListQuery<StockSummaryRow>(
    [...listQueryKeys.reports, 'stock-summary'],
    (params) => inventoryReportApi.stockSummary(params),
    { company_id: companyId ?? undefined }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<StockSummaryRow>[] = [
    {
      key: 'product_name',
      header: 'Product',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.product_sku}
          </span>
        </div>
      ),
    },
    {
      key: 'product_sku',
      header: 'SKU',
      render: (row) => (
        <span className="font-mono text-xs text-text-muted">
          {row.product_sku}
        </span>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{row.warehouse_name}</span>
      ),
    },
    {
      key: 'on_hand',
      header: 'On Hand',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.on_hand)}</span>
      ),
    },
    {
      key: 'reserved',
      header: 'Reserved',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.reserved)}
        </span>
      ),
    },
    {
      key: 'available',
      header: 'Available',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatDecimal(row.available)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load the stock summary.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <FilterBar>
        <Select
          label="Warehouse"
          name="warehouse_filter"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              warehouse_id:
                event.target.value === ''
                  ? undefined
                  : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />
      </FilterBar>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => `${row.product_id}-${row.warehouse_id}-${row.unit_id}`}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search products..."
        emptyTitle="No stock balances found"
        emptyDescription="No product has a stock balance in this scope yet. Balances appear once inventory starts moving."
      />
    </div>
  );
}

/* ------------------------------ Stock card ------------------------------ */

function StockCardTab({ companyId }: { companyId: number | null }) {
  const [productSearch, setProductSearch] = useState('');
  const [productId, setProductId] = useState<number | null>(null);
  const products = useProductOptions(companyId, productSearch);

  // The card needs a product before it can run: the endpoint rejects a request
  // without one, so the picker gates the fetch rather than decorating an error.
  const list = useListQuery<StockCardRow>(
    [...listQueryKeys.reports, 'stock-card', String(productId ?? 'none')],
    (params) => inventoryReportApi.stockCard(params),
    { product_id: productId ?? undefined, per_page: 50 }
  );

  const selected =
    products.find((product) => product.id === productId) ?? undefined;

  const columns: Column<StockCardRow>[] = [
    {
      key: 'occurred_at',
      header: 'Date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.occurred_at)}</span>
      ),
    },
    {
      key: 'reference_label',
      header: 'Reference',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">
            {row.reference_label ?? labelFor.movementType(row.movement_type)}
          </span>
          {row.reference_id && (
            <span className="text-xs text-text-subtle">
              {labelFor.movementType(row.movement_type)}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'in_quantity',
      header: 'In',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-success">
          {Number(row.in_quantity) > 0 ? formatDecimal(row.in_quantity) : '-'}
        </span>
      ),
    },
    {
      key: 'out_quantity',
      header: 'Out',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-danger">
          {Number(row.out_quantity) > 0 ? formatDecimal(row.out_quantity) : '-'}
        </span>
      ),
    },
    {
      key: 'balance',
      header: 'Balance',
      align: 'right',
      render: (row) => (
        // The running balance is the ledger's recorded balance_after, never a
        // client-side sum over the page.
        <span className="font-medium tabular-nums">
          {formatDecimal(row.balance)}
        </span>
      ),
    },
    {
      key: 'total_cost',
      header: 'Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_cost)}
        </span>
      ),
    },
  ];

  if (productId === null) {
    return (
      <Card>
        <CardHeader
          title="Stock card"
          description="The running ledger of one product's stock movements"
        />
        <CardBody>
          <ProductPicker
            products={products}
            search={productSearch}
            onSearchChange={setProductSearch}
            onSelect={(product) => setProductId(product.id)}
          />
          <div className="mt-6 rounded-md border border-dashed border-border bg-surface-alt/40 p-6 text-center">
            <p className="text-sm font-medium text-text">
              Choose a product to open its stock card
            </p>
            <p className="mt-1 text-xs text-text-muted">
              The card lists every ledger movement with its running balance and
              cost. A product is required because a card without one is the
              movement report.
            </p>
          </div>
        </CardBody>
      </Card>
    );
  }

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load the stock card.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader
          title="Stock card"
          description={selected ? selected.name : 'Product ledger'}
        />
        <CardBody>
          <ProductPicker
            products={products}
            search={productSearch}
            onSearchChange={setProductSearch}
            onSelect={(product) => setProductId(product.id)}
            selected={selected}
          />
        </CardBody>
      </Card>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => `${row.occurred_at}-${row.reference_id ?? '0'}`}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchable={false}
        emptyTitle="No movements for this product"
        emptyDescription="This product has no ledger entries in the selected scope yet."
      />
    </div>
  );
}

function ProductPicker({
  products,
  search,
  onSearchChange,
  onSelect,
  selected,
}: {
  products: Product[];
  search: string;
  onSearchChange: (value: string) => void;
  onSelect: (product: Product) => void;
  selected?: Product;
}) {
  return (
    <div className="flex flex-wrap items-end gap-3">
      <Select
        label="Product"
        name="stock_card_product"
        options={products.map((product) => ({
          label: `${product.sku} — ${product.name}`,
          value: product.id,
        }))}
        placeholder="Choose a product"
        value={selected?.id ?? ''}
        onChange={(event) => {
          const found = products.find(
            (product) => product.id === Number(event.target.value)
          );
          if (found) {
            onSelect(found);
          }
        }}
        wrapperClassName="w-full max-w-sm"
      />
      <label className="flex flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">
          Filter products
        </span>
        <input
          type="search"
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder="Name or SKU..."
          className="h-9 w-full max-w-xs rounded-md border border-border bg-surface px-3 text-sm text-text placeholder:text-text-subtle focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
      {selected && (
        <span className="text-xs text-text-muted">
          Showing the ledger for{' '}
          <strong className="text-text">{selected.sku}</strong>
        </span>
      )}
    </div>
  );
}

/* ---------------------------- Stock movements --------------------------- */

/**
 * The movement report flattens product, warehouse and reference onto the row,
 * which the shared `StockMovement` model type does not carry. This describes
 * what the endpoint actually answers.
 */
interface StockMovementReportRow {
  id: number;
  occurred_at: string | null;
  movement_type: string | null;
  reference_type: string | null;
  reference_id: number | null;
  reference_label: string | null;
  product_id: number;
  product_sku: string;
  product_name: string;
  warehouse_id: number;
  warehouse_code: string;
  warehouse_name: string;
  unit_code: string;
  quantity: string;
  balance_after: string;
  unit_cost: string;
  total_cost: string;
  notes: string | null;
}

function StockMovementsTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);

  const list = useListQuery<StockMovementReportRow>(
    [...listQueryKeys.reports, 'stock-movements'],
    (params) =>
      inventoryReportApi.stockMovements(params) as unknown as Promise<
        ApiResponse<StockMovementReportRow[]>
      >,
    { company_id: companyId ?? undefined }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<StockMovementReportRow>[] = [
    {
      key: 'occurred_at',
      header: 'Date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">
          {formatDate(row.occurred_at, true)}
        </span>
      ),
    },
    {
      key: 'product_name',
      header: 'Product',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.product_sku}
          </span>
        </div>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse_name}</span>
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
      key: 'reference_label',
      header: 'Reference',
      render: (row) => (
        <span className="text-text-muted">{row.reference_label}</span>
      ),
    },
    {
      key: 'quantity',
      header: 'Quantity',
      align: 'right',
      render: (row) => {
        const value = Number(row.quantity);

        return (
          <span
            className={
              value < 0
                ? 'font-medium tabular-nums text-danger'
                : 'font-medium tabular-nums text-success'
            }
          >
            {formatDecimal(row.quantity)}
          </span>
        );
      },
    },
    {
      key: 'balance_after',
      header: 'Balance',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.balance_after)}</span>
      ),
    },
    {
      key: 'unit_cost',
      header: 'Unit Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.unit_cost)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load stock movements.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <FilterBar>
        <Select
          label="Warehouse"
          name="movement_warehouse"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              warehouse_id:
                event.target.value === ''
                  ? undefined
                  : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />
        <Select
          label="Movement type"
          name="movement_type"
          options={MOVEMENT_TYPE_OPTIONS}
          placeholder="All types"
          value={list.params.movement_type ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              movement_type: event.target.value || undefined,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
        />
        <Select
          label="Reference"
          name="reference_type"
          options={REFERENCE_OPTIONS}
          placeholder="All references"
          value={list.params.reference_type ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              reference_type: event.target.value || undefined,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
        />
        <DateRangeFilter list={list} />
        <ClearFiltersButton list={list} />
      </FilterBar>

      <div className="flex justify-end">
        <CsvExportButton
          entity="stock-movements"
          params={{
            company_id: companyId ?? undefined,
            warehouse_id: list.params.warehouse_id,
            product_id: list.params.product_id,
            movement_type: list.params.movement_type,
          }}
          fileName={`stock-movements-${new Date().toISOString().slice(0, 10)}.csv`}
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
        searchPlaceholder="Search products..."
        emptyTitle="No movements in this period"
        emptyDescription="No ledger rows matched these filters. Try a wider date range or clear a filter."
      />
    </div>
  );
}

/* ------------------------------ Low stock ------------------------------- */

function LowStockTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);

  const list = useListQuery<LowStockRow>(
    [...listQueryKeys.reports, 'low-stock'],
    (params) => inventoryReportApi.lowStock(params),
    { company_id: companyId ?? undefined }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<LowStockRow>[] = [
    {
      key: 'product_name',
      header: 'Product',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.product_sku}
          </span>
        </div>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{row.warehouse_name}</span>
      ),
    },
    {
      key: 'available',
      header: 'Available',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatDecimal(row.available)}
        </span>
      ),
    },
    {
      key: 'reorder_point',
      header: 'Reorder Point',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.reorder_point)}
        </span>
      ),
    },
    {
      key: 'suggested_reorder_quantity',
      header: 'Suggested Reorder',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums text-primary">
          {formatDecimal(row.suggested_reorder_quantity)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load the low stock report.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <FilterBar>
        <Select
          label="Warehouse"
          name="low_stock_warehouse"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={list.params.warehouse_id ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              warehouse_id:
                event.target.value === ''
                  ? undefined
                  : Number(event.target.value),
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />
      </FilterBar>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => `${row.product_id}-${row.warehouse_id}`}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search products..."
        emptyTitle="Nothing below the reorder point"
        emptyDescription="Every tracked product is above its reorder point in this scope."
      />
    </div>
  );
}

/* ---------------------------- Stock valuation --------------------------- */

function StockValuationTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);
  const [params, setParams] = useState<ListParams>({
    company_id: companyId ?? undefined,
    per_page: 50,
  });

  const query = useQuery({
    queryKey: [...listQueryKeys.reports, 'stock-valuation', params],
    queryFn: () => inventoryReportApi.stockValuation(params),
  });

  useEffect(() => {
    setParams((current) => ({ ...current, company_id: companyId ?? undefined }));
  }, [companyId]);

  const rows: StockValuationRow[] = query.data?.data?.rows ?? [];
  const total = query.data?.data?.total ?? null;

  const columns: GroupColumn<StockValuationRow>[] = [
    {
      key: 'product_name',
      header: 'Product',
      footer: 'Total valuation',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          <span className="font-mono text-xs text-text-subtle">
            {row.product_sku}
          </span>
        </div>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse_name}</span>
      ),
    },
    {
      key: 'on_hand',
      header: 'On Hand',
      align: 'right',
      footer: <span className="tabular-nums">{total ? formatDecimal(total) : ''}</span>,
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.on_hand)}</span>
      ),
    },
    {
      key: 'average_cost',
      header: 'Average Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.average_cost)}
        </span>
      ),
    },
    {
      key: 'last_cost',
      header: 'Last Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.last_cost)}
        </span>
      ),
    },
    {
      key: 'on_hand_value',
      header: 'On Hand Value',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {total ? formatMoneyString(total) : ''}
        </span>
      ),
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.on_hand_value)}
        </span>
      ),
    },
  ];

  const error = query.error
    ? reportErrorMessage(query.error, 'Failed to load the stock valuation.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <FilterBar>
        <Select
          label="Warehouse"
          name="valuation_warehouse"
          options={warehouseOptions}
          placeholder="All warehouses"
          value={params.warehouse_id ?? ''}
          onChange={(event) =>
            setParams((current) => ({
              ...current,
              warehouse_id:
                event.target.value === ''
                  ? undefined
                  : Number(event.target.value),
            }))
          }
          wrapperClassName="w-full max-w-xs"
          disabled={companyId === null}
        />
      </FilterBar>

      <GroupTable<StockValuationRow>
        columns={columns}
        rows={rows}
        rowKey={(row) => `${row.product_id}-${row.warehouse_id}`}
        loading={query.isLoading}
        error={error}
        onRetry={query.refetch}
        showFooter={rows.length > 0}
        emptyTitle="No stock to value"
        emptyDescription="No balances carry an on-hand quantity in this scope, so the valuation is zero."
      />

      {total !== null && (
        <Card>
          <CardBody className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs font-medium text-text-muted">
                Total stock valuation
              </p>
              <p className="text-lg font-semibold tabular-nums text-text">
                {formatMoneyString(total)}
              </p>
            </div>
            <p className="max-w-sm text-xs text-text-subtle">
              On hand valued at each balance's weighted average cost, exactly as
              the report computed it.
            </p>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

/* ----------------------------- Stock opnames ---------------------------- */

/**
 * The opname summary document the report answers. The shared `StockOpname`
 * model carries its relations instead of these flattened summary columns, so
 * the report's own shape is described here.
 */
interface StockOpnameSummaryRow {
  id: number;
  number: string;
  opname_date: string | null;
  status: string | null;
  warehouse_code: string;
  warehouse_name: string;
  items_count: number;
  system_quantity: string;
  counted_quantity: string;
  variance_quantity: string;
  variance_value: string;
}

function StockOpnamesTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);

  const list = useListQuery<StockOpnameSummaryRow>(
    [...listQueryKeys.reports, 'stock-opnames'],
    (params) =>
      inventoryReportApi.stockOpnames(params) as unknown as Promise<
        ApiResponse<StockOpnameSummaryRow[]>
      >,
    { company_id: companyId ?? undefined }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<StockOpnameSummaryRow>[] = [
    {
      key: 'number',
      header: 'Number',
      render: (row) => (
        <span className="font-mono text-xs font-medium text-text">
          {row.number}
        </span>
      ),
    },
    {
      key: 'opname_date',
      header: 'Date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">
          {formatDate(row.opname_date as string | null)}
        </span>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{row.warehouse_name}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'items_count',
      header: 'Items',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.items_count)}</span>
      ),
    },
    {
      key: 'variance_quantity',
      header: 'Variance Qty',
      align: 'right',
      render: (row) => {
        const value = Number(row.variance_quantity ?? 0);

        return (
          <span
            className={
              value === 0
                ? 'tabular-nums text-text-muted'
                : value < 0
                  ? 'font-medium tabular-nums text-danger'
                  : 'font-medium tabular-nums text-success'
            }
          >
            {formatDecimal(row.variance_quantity)}
          </span>
        );
      },
    },
    {
      key: 'variance_value',
      header: 'Variance Value',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.variance_value)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load the stock opname report.')
    : null;

  return (
    <FilterBar>
      <Select
        label="Warehouse"
        name="opname_warehouse"
        options={warehouseOptions}
        placeholder="All warehouses"
        value={list.params.warehouse_id ?? ''}
        onChange={(event) =>
          list.onParamsChange({
            warehouse_id:
              event.target.value === ''
                ? undefined
                : Number(event.target.value),
            page: 1,
          })
        }
        wrapperClassName="w-full max-w-xs"
        disabled={companyId === null}
      />
      <Select
        label="Status"
        name="opname_status"
        options={['draft', 'counting', 'review', 'approved', 'posted'].map(
          (value) => ({ label: labelFor.documentStatus(value), value })
        )}
        placeholder="All statuses"
        value={list.params.status ?? ''}
        onChange={(event) =>
          list.onParamsChange({
            status: event.target.value || undefined,
            page: 1,
          })
        }
        wrapperClassName="w-full max-w-xs"
      />
      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => Number(row.id)}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchable={false}
        emptyTitle="No stock opnames"
        emptyDescription="No stock opname documents exist in this scope yet."
      />
    </FilterBar>
  );
}

/* -------------------------- Warehouse transfers ------------------------- */

/**
 * The transfer summary document the report answers, flattened the same way the
 * opname summary is.
 */
interface WarehouseTransferSummaryRow {
  id: number;
  number: string;
  transfer_date: string | null;
  status: string | null;
  from_warehouse_code: string;
  from_warehouse_name: string;
  to_warehouse_code: string;
  to_warehouse_name: string;
  items_count: number;
  total_quantity: string;
  total_received: string;
}

function WarehouseTransfersTab({ companyId }: { companyId: number | null }) {
  const warehouseOptions = useWarehouseOptions(companyId);

  const list = useListQuery<WarehouseTransferSummaryRow>(
    [...listQueryKeys.reports, 'warehouse-transfers'],
    (params) =>
      inventoryReportApi.warehouseTransfers(params) as unknown as Promise<
        ApiResponse<WarehouseTransferSummaryRow[]>
      >,
    { company_id: companyId ?? undefined }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<WarehouseTransferSummaryRow>[] = [
    {
      key: 'number',
      header: 'Number',
      render: (row) => (
        <span className="font-mono text-xs font-medium text-text">
          {row.number}
        </span>
      ),
    },
    {
      key: 'transfer_date',
      header: 'Date',
      sortable: true,
      render: (row) => (
        <span className="text-text-muted">{formatDate(row.transfer_date)}</span>
      ),
    },
    {
      key: 'from_warehouse_name',
      header: 'From',
      render: (row) => (
        <span className="text-text-muted">{row.from_warehouse_name}</span>
      ),
    },
    {
      key: 'to_warehouse_name',
      header: 'To',
      render: (row) => (
        <span className="text-text-muted">{row.to_warehouse_name}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusBadge status={row.status} />,
    },
    {
      key: 'items_count',
      header: 'Items',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.items_count)}</span>
      ),
    },
    {
      key: 'total_quantity',
      header: 'Qty',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.total_quantity)}
        </span>
      ),
    },
    {
      key: 'total_received',
      header: 'Received',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.total_received)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(
        list.error,
        'Failed to load the warehouse transfer report.'
      )
    : null;

  return (
    <FilterBar>
      <Select
        label="Warehouse"
        name="transfer_warehouse"
        options={warehouseOptions}
        placeholder="All warehouses"
        value={list.params.warehouse_id ?? ''}
        onChange={(event) =>
          list.onParamsChange({
            warehouse_id:
              event.target.value === ''
                ? undefined
                : Number(event.target.value),
            page: 1,
          })
        }
        wrapperClassName="w-full max-w-xs"
        disabled={companyId === null}
      />
      <Select
        label="Status"
        name="transfer_status"
        options={[
          'draft',
          'submitted',
          'approved',
          'shipped',
          'received',
          'completed',
          'cancelled',
        ].map((value) => ({
          label: labelFor.documentStatus(value),
          value,
        }))}
        placeholder="All statuses"
        value={list.params.status ?? ''}
        onChange={(event) =>
          list.onParamsChange({
            status: event.target.value || undefined,
            page: 1,
          })
        }
        wrapperClassName="w-full max-w-xs"
      />
      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => Number(row.id)}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchable={false}
        emptyTitle="No warehouse transfers"
        emptyDescription="No transfer documents exist in this scope yet."
      />
    </FilterBar>
  );
}

/* ----------------------------- Layout helpers --------------------------- */

function FilterBar({ children }: { children: React.ReactNode }) {
  return <div className="flex flex-wrap gap-3">{children}</div>;
}

function ClearFiltersButton({
  list,
}: {
  list: {
    onParamsChange: (params: Partial<ListParams>) => void;
    params: ListParams;
  };
}) {
  const hasFilters =
    list.params.warehouse_id !== undefined ||
    list.params.movement_type !== undefined ||
    list.params.reference_type !== undefined ||
    list.params.date_from !== undefined ||
    list.params.date_to !== undefined;

  if (!hasFilters) {
    return null;
  }

  return (
    <button
      type="button"
      onClick={() =>
        list.onParamsChange({
          warehouse_id: undefined,
          movement_type: undefined,
          reference_type: undefined,
          date_from: undefined,
          date_to: undefined,
          page: 1,
        })
      }
      className="h-9 self-end rounded-md border border-border bg-surface px-3 text-xs font-medium text-text-muted hover:bg-surface-alt hover:text-text"
    >
      Clear filters
    </button>
  );
}

function DateRangeFilter({
  list,
}: {
  list: {
    onParamsChange: (params: Partial<ListParams>) => void;
    params: ListParams;
  };
}) {
  return (
    <>
      <label className="flex w-full max-w-xs flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">From date</span>
        <input
          type="date"
          value={list.params.date_from ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_from: event.target.value || undefined,
              page: 1,
            })
          }
          className="h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
      <label className="flex w-full max-w-xs flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">To date</span>
        <input
          type="date"
          value={list.params.date_to ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              date_to: event.target.value || undefined,
              page: 1,
            })
          }
          className="h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
    </>
  );
}