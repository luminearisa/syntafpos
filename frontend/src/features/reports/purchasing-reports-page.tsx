import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { purchasingReportApi, supplierApi, warehouseApi } from '@/api/services';
import type { ApiResponse, ListParams } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useListQuery } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { PageHeader } from '@/components/ui/state';
import { Select } from '@/components/ui/input';
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
 * Purchasing reports (spec §33): read-only aggregations over the purchasing
 * documents.
 *
 * Every figure is the API's own answer. An empty filter window shows a real
 * empty state, never a demo total (§51); money and quantities are only ever
 * displayed through the formatters (§46).
 *
 * The shared `ReportRow` type is an index signature of `unknown`, so each
 * report's payload shape is described here and narrowed once at the query
 * boundary; the columns then read typed fields.
 */

interface PurchaseSummaryTotals {
  total_orders: number;
  total_quantity: string;
  total_gross: string;
  total_discount: string;
  total_tax: string;
  total_grand_total: string;
}

interface PurchaseSummaryGroup {
  key: string;
  label: string;
  order_count: number;
  total_quantity: string;
  total_gross: string;
  total_discount: string;
  total_tax: string;
  total_grand_total: string;
}

interface PurchaseSummaryResponse {
  totals: PurchaseSummaryTotals;
  groups: PurchaseSummaryGroup[];
}

/**
 * Branch/warehouse balance rows carry `key`/`label`, supplier rows carry the
 * supplier's own id/name. Only the label source differs, so the tab normalises
 * both to `PurchaseBalanceRow` before rendering; no figure is touched.
 */
interface PurchaseSupplierRow {
  supplier_id: number;
  supplier_code: string | null;
  supplier_name: string;
  order_count: number;
  return_count: number;
  total_purchased: string;
  total_received: string;
  total_return: string;
  net_purchased: string;
}

interface PurchaseDimensionRow {
  key: string;
  label: string;
  order_count: number;
  return_count: number;
  total_purchased: string;
  total_received: string;
  total_return: string;
  net_purchased: string;
}

interface PurchaseBalanceRow {
  key: string;
  label: string;
  order_count: number;
  return_count: number;
  total_purchased: string;
  total_received: string;
  total_return: string;
  net_purchased: string;
}

interface PurchaseProductRow {
  product_id: number;
  product_sku: string | null;
  product_name: string;
  order_count: number;
  quantity_ordered: string;
  quantity_received: string;
  remaining_quantity: string;
  gross_value: string;
  net_value: string;
  tax_amount: string;
  average_unit_cost: string;
}

interface PurchaseReturnTotals {
  total_returns: number;
  total_quantity: string;
  total_amount: string;
}

interface PurchaseReturnGroup {
  key: string;
  label: string;
  product_sku: string | null;
  return_count: number;
  total_quantity: string;
  total_amount: string;
}

interface PurchaseReturnResponse {
  totals: PurchaseReturnTotals;
  groups: PurchaseReturnGroup[];
}

interface OutstandingRow {
  purchase_order_id: number;
  purchase_order_number: string;
  order_date: string;
  status: string;
  grand_total: string;
  supplier_id: number;
  supplier_name: string;
  warehouse_id: number;
  warehouse_name: string;
  product_id: number;
  product_name: string;
  quantity_ordered: string;
  quantity_received: string;
  remaining_quantity: string;
  ordered_value: string;
  received_value: string;
  remaining_value: string;
}

interface PurchaseDetailRow {
  id: number;
  purchase_order_id: number;
  product_id: number;
  quantity: string;
  remaining_quantity: string;
  unit_price: string;
  net_price: string;
  tax_amount: string;
  subtotal: string;
  purchase_order: {
    id: number;
    number: string;
    order_date: string | null;
    status: string;
    grand_total: string;
  };
  product: { id: number; sku: string; name: string } | null;
  supplier: { id: number; name: string } | null;
  warehouse: { id: number; code: string; name: string } | null;
}

type PurchasingTab =
  | 'summary'
  | 'detail'
  | 'by-dimension'
  | 'returns'
  | 'outstanding';

const TABS: readonly TabItem<PurchasingTab>[] = [
  { key: 'summary', label: 'Purchase Summary' },
  { key: 'detail', label: 'Purchase Detail' },
  { key: 'by-dimension', label: 'By Supplier / Product / Branch / Warehouse' },
  { key: 'returns', label: 'Returns' },
  { key: 'outstanding', label: 'Outstanding POs' },
];

/**
 * Labels for the report's grouping dimensions. The shared `labelFor` helper
 * covers model enums, not report dimensions, so they live here.
 */
const DIMENSION_LABELS: Record<string, string> = {
  supplier: 'Supplier',
  branch: 'Branch',
  warehouse: 'Warehouse',
  product: 'Product',
  month: 'Month',
  status: 'Status',
  date: 'Date',
};

function dimensionLabel(value: string): string {
  return DIMENSION_LABELS[value] ?? value;
}

const GROUP_BY_OPTIONS = [
  'supplier',
  'branch',
  'warehouse',
  'product',
  'month',
  'status',
].map((value) => ({ label: dimensionLabel(value), value }));

type Dimension = 'supplier' | 'product' | 'branch' | 'warehouse';

const DIMENSION_OPTIONS: { label: string; value: Dimension }[] = [
  { label: 'By Supplier', value: 'supplier' },
  { label: 'By Product', value: 'product' },
  { label: 'By Branch', value: 'branch' },
  { label: 'By Warehouse', value: 'warehouse' },
];

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

export default function PurchasingReportsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const [tab, setTab] = useState<PurchasingTab>('summary');

  if (!can('reports.purchasing')) {
    return <ForbiddenState permission="reports.purchasing" />;
  }

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Purchasing Reports"
        description="What was bought, from whom, for which location, and what is still to receive."
      />

      <Tabs tabs={TABS} active={tab} onChange={setTab} />

      {tab === 'summary' && <SummaryTab companyId={companyId} />}
      {tab === 'detail' && <DetailTab companyId={companyId} />}
      {tab === 'by-dimension' && <DimensionTab companyId={companyId} />}
      {tab === 'returns' && <ReturnsTab companyId={companyId} />}
      {tab === 'outstanding' && <OutstandingTab companyId={companyId} />}
    </div>
  );
}

/* ---------------------------- Filter options ---------------------------- */

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

function useSupplierOptions(companyId: number | null) {
  const { data } = useQuery({
    queryKey: [...listQueryKeys.suppliers, 'report-filter', companyId],
    queryFn: () =>
      supplierApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
  });

  return (data?.data ?? []).map((supplier) => ({
    label: supplier.name,
    value: supplier.id,
  }));
}

function useSharedFilters(companyId: number | null) {
  const [params, setParams] = useState<ListParams>({
    company_id: companyId ?? undefined,
  });

  useEffect(() => {
    setParams((current) => ({ ...current, company_id: companyId ?? undefined }));
  }, [companyId]);

  const patch = (partial: Partial<ListParams>) =>
    setParams((current) => ({ ...current, ...partial }));

  return { params, patch };
}

function SharedFilterBar({
  companyId,
  params,
  patch,
  showStatus = false,
}: {
  companyId: number | null;
  params: ListParams;
  patch: (partial: Partial<ListParams>) => void;
  showStatus?: boolean;
}) {
  const warehouseOptions = useWarehouseOptions(companyId);
  const supplierOptions = useSupplierOptions(companyId);

  return (
    <div className="flex flex-wrap gap-3">
      <Select
        label="Supplier"
        name="supplier_filter"
        options={supplierOptions}
        placeholder="All suppliers"
        value={params.supplier_id ?? ''}
        onChange={(event) =>
          patch({
            supplier_id:
              event.target.value === '' ? undefined : Number(event.target.value),
          })
        }
        wrapperClassName="w-full max-w-xs"
        disabled={companyId === null}
      />
      <Select
        label="Warehouse"
        name="warehouse_filter"
        options={warehouseOptions}
        placeholder="All warehouses"
        value={params.warehouse_id ?? ''}
        onChange={(event) =>
          patch({
            warehouse_id:
              event.target.value === '' ? undefined : Number(event.target.value),
          })
        }
        wrapperClassName="w-full max-w-xs"
        disabled={companyId === null}
      />
      <label className="flex w-full max-w-xs flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">From date</span>
        <input
          type="date"
          value={params.start_date ?? ''}
          onChange={(event) =>
            patch({ start_date: event.target.value || undefined })
          }
          className="h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
      <label className="flex w-full max-w-xs flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">To date</span>
        <input
          type="date"
          value={params.end_date ?? ''}
          onChange={(event) =>
            patch({ end_date: event.target.value || undefined })
          }
          className="h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
      {showStatus && (
        <Select
          label="Status"
          name="status_filter"
          options={[
            'draft',
            'submitted',
            'approved',
            'sent',
            'partially_received',
            'received',
            'closed',
            'cancelled',
          ].map((value) => ({ label: labelFor.documentStatus(value), value }))}
          placeholder="All statuses"
          value={params.status ?? ''}
          onChange={(event) =>
            patch({ status: event.target.value || undefined })
          }
          wrapperClassName="w-full max-w-xs"
        />
      )}
    </div>
  );
}

/* ----------------------------- Purchase summary ------------------------- */

function SummaryTab({ companyId }: { companyId: number | null }) {
  const { params, patch } = useSharedFilters(companyId);
  const [groupBy, setGroupBy] = useState<string>('supplier');

  const query = useQuery({
    queryKey: [...listQueryKeys.reports, 'purchasing-summary', params, groupBy],
    queryFn: () =>
      purchasingReportApi.summary({
        ...params,
        group_by: groupBy,
      } as ListParams & { group_by: string }),
  });

  // The header totals the whole filter window; the groups are its breakdown.
  const data = query.data?.data as PurchaseSummaryResponse | undefined;
  const totals = data?.totals;
  const groups = data?.groups ?? [];

  const columns: GroupColumn<PurchaseSummaryGroup>[] = [
    {
      key: 'label',
      header: 'Group',
      footer: groups.length > 0 ? `${groups.length} groups` : null,
      render: (row) => (
        <span className="font-medium text-text">{row.label || '-'}</span>
      ),
    },
    {
      key: 'order_count',
      header: 'Orders',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {totals ? formatDecimal(totals.total_orders) : ''}
        </span>
      ),
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.order_count)}</span>
      ),
    },
    {
      key: 'total_quantity',
      header: 'Quantity',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.total_quantity)}
        </span>
      ),
    },
    {
      key: 'total_gross',
      header: 'Gross',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_gross)}
        </span>
      ),
    },
    {
      key: 'total_discount',
      header: 'Discount',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_discount)}
        </span>
      ),
    },
    {
      key: 'total_tax',
      header: 'Tax',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_tax)}
        </span>
      ),
    },
    {
      key: 'total_grand_total',
      header: 'Grand Total',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {totals ? formatMoneyString(totals.total_grand_total) : ''}
        </span>
      ),
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.total_grand_total)}
        </span>
      ),
    },
  ];

  const error = query.error
    ? reportErrorMessage(query.error, 'Failed to load the purchase summary.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <SharedFilterBar
        companyId={companyId}
        params={params}
        patch={patch}
        showStatus
      />

      <div className="flex flex-wrap items-end gap-3">
        <Select
          label="Group by"
          name="summary_group_by"
          options={GROUP_BY_OPTIONS}
          value={groupBy}
          onChange={(event) => setGroupBy(event.target.value)}
          wrapperClassName="w-full max-w-xs"
        />
        <div className="flex flex-wrap gap-2">
          <SummaryStat
            label="Orders"
            value={totals ? formatDecimal(totals.total_orders) : '0'}
          />
          <SummaryStat
            label="Quantity"
            value={totals ? formatDecimal(totals.total_quantity) : '0'}
          />
          <SummaryStat
            label="Grand total"
            value={totals ? formatMoneyString(totals.total_grand_total) : '0'}
            emphasis
          />
        </div>
      </div>

      <GroupTable<PurchaseSummaryGroup>
        columns={columns}
        rows={groups}
        rowKey={(row) => row.key}
        loading={query.isLoading}
        error={error}
        onRetry={query.refetch}
        showFooter={groups.length > 0}
        emptyTitle="No purchases in this period"
        emptyDescription="No purchase orders matched these filters. Widen the date range or clear a filter and run the report again."
      />
    </div>
  );
}

function SummaryStat({
  label,
  value,
  emphasis = false,
}: {
  label: string;
  value: string;
  emphasis?: boolean;
}) {
  return (
    <div
      className={
        emphasis
          ? 'rounded-md border border-primary/30 bg-primary-soft px-3 py-2'
          : 'rounded-md border border-border bg-surface px-3 py-2'
      }
    >
      <p className="text-[11px] font-medium text-text-muted">{label}</p>
      <p
        className={
          emphasis
            ? 'text-sm font-semibold tabular-nums text-primary'
            : 'text-sm font-semibold tabular-nums text-text'
        }
      >
        {value}
      </p>
    </div>
  );
}

/* ----------------------------- Purchase detail -------------------------- */

function DetailTab({ companyId }: { companyId: number | null }) {
  const list = useListQuery<PurchaseDetailRow>(
    [...listQueryKeys.reports, 'purchasing-detail'],
    (params) =>
      purchasingReportApi.detail(
        params
      ) as unknown as Promise<ApiResponse<PurchaseDetailRow[]>>,
    { company_id: companyId ?? undefined, per_page: 50 }
  );

  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const columns: Column<PurchaseDetailRow>[] = [
    {
      key: 'purchase_order',
      header: 'PO Number',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-mono text-xs font-medium text-text">
            {row.purchase_order?.number ?? '-'}
          </span>
          <span className="text-xs text-text-subtle">
            {formatDate(row.purchase_order?.order_date)}
          </span>
        </div>
      ),
    },
    {
      key: 'supplier',
      header: 'Supplier',
      render: (row) => (
        <span className="text-text-muted">{row.supplier?.name ?? '-'}</span>
      ),
    },
    {
      key: 'product',
      header: 'Product',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">
            {row.product?.name ?? '-'}
          </span>
          {row.product?.sku && (
            <span className="font-mono text-xs text-text-subtle">
              {row.product.sku}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">
          {row.warehouse ? `${row.warehouse.code} — ${row.warehouse.name}` : '-'}
        </span>
      ),
    },
    {
      key: 'quantity',
      header: 'Qty',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.quantity)}</span>
      ),
    },
    {
      key: 'remaining_quantity',
      header: 'Remaining',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.remaining_quantity)}
        </span>
      ),
    },
    {
      key: 'unit_price',
      header: 'Unit Price',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.unit_price)}
        </span>
      ),
    },
    {
      key: 'net_price',
      header: 'Net Price',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.net_price)}
        </span>
      ),
    },
    {
      key: 'tax_amount',
      header: 'Tax',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.tax_amount)}
        </span>
      ),
    },
    {
      key: 'subtotal',
      header: 'Subtotal',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.subtotal)}
        </span>
      ),
    },
  ];

  const errorMessage = list.error
    ? reportErrorMessage(list.error, 'Failed to load the purchase detail.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <DetailFilterBar companyId={companyId} list={list} />
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
        emptyTitle="No purchase lines in this period"
        emptyDescription="No purchase order line matched these filters. Widen the range or clear a filter and run the report again."
      />
    </div>
  );
}

function DetailFilterBar({
  companyId,
  list,
}: {
  companyId: number | null;
  list: {
    onParamsChange: (params: Partial<ListParams>) => void;
    params: ListParams;
  };
}) {
  const warehouseOptions = useWarehouseOptions(companyId);
  const supplierOptions = useSupplierOptions(companyId);

  return (
    <div className="flex flex-wrap gap-3">
      <Select
        label="Supplier"
        name="detail_supplier"
        options={supplierOptions}
        placeholder="All suppliers"
        value={list.params.supplier_id ?? ''}
        onChange={(event) =>
          list.onParamsChange({
            supplier_id:
              event.target.value === '' ? undefined : Number(event.target.value),
            page: 1,
          })
        }
        wrapperClassName="w-full max-w-xs"
        disabled={companyId === null}
      />
      <Select
        label="Warehouse"
        name="detail_warehouse"
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
      <label className="flex w-full max-w-xs flex-col gap-1">
        <span className="text-xs font-medium text-text-muted">From date</span>
        <input
          type="date"
          value={list.params.start_date ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              start_date: event.target.value || undefined,
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
          value={list.params.end_date ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              end_date: event.target.value || undefined,
              page: 1,
            })
          }
          className="h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </label>
    </div>
  );
}

/* --------- By supplier / product / branch / warehouse: one component ---- */

function DimensionTab({ companyId }: { companyId: number | null }) {
  const { params, patch } = useSharedFilters(companyId);
  const [dimension, setDimension] = useState<Dimension>('supplier');

  const query = useQuery({
    queryKey: [
      ...listQueryKeys.reports,
      'purchasing-by-dimension',
      dimension,
      params,
    ],
    queryFn: () => {
      switch (dimension) {
        case 'supplier':
          return purchasingReportApi.bySupplier(params);
        case 'product':
          return purchasingReportApi.byProduct(params);
        case 'branch':
          return purchasingReportApi.byBranch(params);
        case 'warehouse':
          return purchasingReportApi.byWarehouse(params);
      }
    },
  });

  // One component, one table: the balance dimensions differ from the supplier
  // rows only in where the label comes from, so they are normalised here.
  const rawRows = (query.data?.data ?? []) as unknown as
    | PurchaseSupplierRow[]
    | PurchaseDimensionRow[]
    | PurchaseProductRow[]
    | undefined;

  const balanceRows: PurchaseBalanceRow[] =
    dimension !== 'product' && rawRows
      ? (rawRows as PurchaseSupplierRow[] | PurchaseDimensionRow[]).map((row) =>
          'supplier_id' in row
            ? {
                key: String(row.supplier_id),
                label: row.supplier_name,
                order_count: row.order_count,
                return_count: row.return_count,
                total_purchased: row.total_purchased,
                total_received: row.total_received,
                total_return: row.total_return,
                net_purchased: row.net_purchased,
              }
            : row
        )
      : [];

  const productRows: PurchaseProductRow[] =
    dimension === 'product'
      ? (rawRows as PurchaseProductRow[] | undefined) ?? []
      : [];

  const error = query.error
    ? reportErrorMessage(
        query.error,
        'Failed to load the purchasing breakdown.'
      )
    : null;

  const emptyDescription = `No ${dimension.replace('_', ' ')} recorded a purchase with these filters. Widen the range or clear a filter and run the report again.`;

  return (
    <div className="flex flex-col gap-4">
      <SharedFilterBar companyId={companyId} params={params} patch={patch} />

      <Select
        label="Breakdown"
        name="dimension_switch"
        options={DIMENSION_OPTIONS}
        value={dimension}
        onChange={(event) => setDimension(event.target.value as Dimension)}
        wrapperClassName="w-full max-w-xs"
      />

      {dimension === 'product' ? (
        <GroupTable<PurchaseProductRow>
          columns={productColumns(productRows)}
          rows={productRows}
          rowKey={(row) => row.product_id}
          loading={query.isLoading}
          error={error}
          onRetry={query.refetch}
          emptyTitle="No purchasing in this period"
          emptyDescription={emptyDescription}
        />
      ) : (
        <GroupTable<PurchaseBalanceRow>
          columns={balanceColumns(dimension, balanceRows)}
          rows={balanceRows}
          rowKey={(row) => row.key}
          loading={query.isLoading}
          error={error}
          onRetry={query.refetch}
          emptyTitle="No purchasing in this period"
          emptyDescription={emptyDescription}
        />
      )}
    </div>
  );
}

function balanceColumns(
  dimension: Dimension,
  rows: PurchaseBalanceRow[]
): GroupColumn<PurchaseBalanceRow>[] {
  const labelHeader =
    dimension === 'supplier'
      ? 'Supplier'
      : dimension === 'branch'
        ? 'Branch'
        : 'Warehouse';

  return [
    {
      key: 'label',
      header: labelHeader,
      footer: rows.length > 0 ? `${rows.length} rows` : null,
      render: (row) => (
        <span className="font-medium text-text">{row.label || '-'}</span>
      ),
    },
    {
      key: 'order_count',
      header: 'Orders',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.order_count)}</span>
      ),
    },
    {
      key: 'return_count',
      header: 'Returns',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.return_count)}
        </span>
      ),
    },
    {
      key: 'total_purchased',
      header: 'Purchased',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatMoneyString(row.total_purchased)}
        </span>
      ),
    },
    {
      key: 'total_received',
      header: 'Received',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_received)}
        </span>
      ),
    },
    {
      key: 'total_return',
      header: 'Returned',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.total_return)}
        </span>
      ),
    },
    {
      key: 'net_purchased',
      header: 'Net',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.net_purchased)}
        </span>
      ),
    },
  ];
}

function productColumns(
  rows: PurchaseProductRow[]
): GroupColumn<PurchaseProductRow>[] {
  return [
    {
      key: 'product_name',
      header: 'Product',
      footer: rows.length > 0 ? `${rows.length} products` : null,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.product_name}</span>
          {row.product_sku && (
            <span className="font-mono text-xs text-text-subtle">
              {row.product_sku}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'order_count',
      header: 'Orders',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.order_count)}</span>
      ),
    },
    {
      key: 'quantity_ordered',
      header: 'Ordered Qty',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.quantity_ordered)}
        </span>
      ),
    },
    {
      key: 'quantity_received',
      header: 'Received Qty',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.quantity_received)}
        </span>
      ),
    },
    {
      key: 'remaining_quantity',
      header: 'Remaining Qty',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.remaining_quantity)}
        </span>
      ),
    },
    {
      key: 'gross_value',
      header: 'Gross Value',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.gross_value)}
        </span>
      ),
    },
    {
      key: 'net_value',
      header: 'Net Value',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.net_value)}
        </span>
      ),
    },
    {
      key: 'tax_amount',
      header: 'Tax',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.tax_amount)}
        </span>
      ),
    },
    {
      key: 'average_unit_cost',
      header: 'Avg Unit Cost',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.average_unit_cost)}
        </span>
      ),
    },
  ];
}

/* -------------------------------- Returns ------------------------------- */

function ReturnsTab({ companyId }: { companyId: number | null }) {
  const { params, patch } = useSharedFilters(companyId);
  const [groupBy, setGroupBy] = useState<string>('supplier');

  const query = useQuery({
    queryKey: [...listQueryKeys.reports, 'purchasing-returns', params, groupBy],
    queryFn: () =>
      purchasingReportApi.returns({
        ...params,
        group_by: groupBy,
      } as ListParams & { group_by: string }),
  });

  const data = query.data?.data as PurchaseReturnResponse | undefined;
  const totals = data?.totals;
  const groups = data?.groups ?? [];

  const columns: GroupColumn<PurchaseReturnGroup>[] = [
    {
      key: 'label',
      header: 'Group',
      footer: groups.length > 0 ? `${groups.length} groups` : null,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-text">{row.label}</span>
          {row.product_sku && (
            <span className="font-mono text-xs text-text-subtle">
              {row.product_sku}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'return_count',
      header: 'Returns',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {totals ? formatDecimal(totals.total_returns) : ''}
        </span>
      ),
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.return_count)}</span>
      ),
    },
    {
      key: 'total_quantity',
      header: 'Quantity',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {totals ? formatDecimal(totals.total_quantity) : ''}
        </span>
      ),
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.total_quantity)}</span>
      ),
    },
    {
      key: 'total_amount',
      header: 'Amount',
      align: 'right',
      footer: (
        <span className="tabular-nums">
          {totals ? formatMoneyString(totals.total_amount) : ''}
        </span>
      ),
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.total_amount)}
        </span>
      ),
    },
  ];

  const error = query.error
    ? reportErrorMessage(query.error, 'Failed to load the returns report.')
    : null;

  return (
    <div className="flex flex-col gap-4">
      <SharedFilterBar companyId={companyId} params={params} patch={patch} />

      <Select
        label="Group by"
        name="returns_group_by"
        options={['supplier', 'product', 'date'].map((value) => ({
          label: dimensionLabel(value),
          value,
        }))}
        value={groupBy}
        onChange={(event) => setGroupBy(event.target.value)}
        wrapperClassName="w-full max-w-xs"
      />

      <GroupTable<PurchaseReturnGroup>
        columns={columns}
        rows={groups}
        rowKey={(row) => row.key}
        loading={query.isLoading}
        error={error}
        onRetry={query.refetch}
        showFooter={groups.length > 0}
        emptyTitle="No returns in this period"
        emptyDescription="Only posted returns are summed, and none matched these filters."
      />
    </div>
  );
}

/* ------------------------------ Outstanding ----------------------------- */

function OutstandingTab({ companyId }: { companyId: number | null }) {
  const { params, patch } = useSharedFilters(companyId);

  const query = useQuery({
    queryKey: [...listQueryKeys.reports, 'purchasing-outstanding', params],
    queryFn: () => purchasingReportApi.outstanding(params),
  });

  const rows = (query.data?.data ?? []) as unknown as OutstandingRow[];

  const columns: GroupColumn<OutstandingRow>[] = [
    {
      key: 'purchase_order_number',
      header: 'PO',
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-mono text-xs font-medium text-text">
            {String(row.purchase_order_number ?? '-')}
          </span>
          <span className="text-xs text-text-subtle">
            {formatDate(row.order_date as string | null)}
          </span>
        </div>
      ),
    },
    {
      key: 'supplier_name',
      header: 'Supplier',
      render: (row) => (
        <span className="text-text-muted">{String(row.supplier_name ?? '-')}</span>
      ),
    },
    {
      key: 'warehouse_name',
      header: 'Warehouse',
      render: (row) => (
        <span className="text-text-muted">{String(row.warehouse_name ?? '-')}</span>
      ),
    },
    {
      key: 'product_name',
      header: 'Product',
      render: (row) => (
        <span className="font-medium text-text">
          {String(row.product_name ?? '-')}
        </span>
      ),
    },
    {
      key: 'quantity_ordered',
      header: 'Ordered',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums">
          {formatDecimal(row.quantity_ordered)}
        </span>
      ),
    },
    {
      key: 'quantity_received',
      header: 'Received',
      align: 'right',
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatDecimal(row.quantity_received)}
        </span>
      ),
    },
    {
      key: 'remaining_quantity',
      header: 'Remaining Qty',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums text-warning">
          {formatDecimal(row.remaining_quantity)}
        </span>
      ),
    },
    {
      key: 'remaining_value',
      header: 'Remaining Value',
      align: 'right',
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatMoneyString(row.remaining_value)}
        </span>
      ),
    },
  ];

  const error = query.error
    ? reportErrorMessage(
        query.error,
        'Failed to load the outstanding purchase orders.'
      )
    : null;

  const exportParams: ListParams = {
    company_id: companyId ?? undefined,
    supplier_id: params.supplier_id,
    warehouse_id: params.warehouse_id,
    status: params.status,
  };

  return (
    <div className="flex flex-col gap-4">
      <SharedFilterBar
        companyId={companyId}
        params={params}
        patch={patch}
        showStatus
      />

      <div className="flex justify-end">
        <CsvExportButton
          entity="purchase-orders"
          params={exportParams}
          fileName={`outstanding-purchase-orders-${new Date()
            .toISOString()
            .slice(0, 10)}.csv`}
          label="Export outstanding CSV"
        />
      </div>

      <GroupTable<OutstandingRow>
        columns={columns}
        rows={rows}
        rowKey={(row) =>
          `${row.purchase_order_id}-${row.product_id}`
        }
        loading={query.isLoading}
        error={error}
        onRetry={query.refetch}
        emptyTitle="Nothing outstanding"
        emptyDescription="Every purchase order in scope is fully received, closed or cancelled. This is the actionable buying list, and it is empty."
      />

      {rows.length > 0 && (
        <Card>
          <CardHeader
            title="Remaining value to receive"
            description="Valued at each line's unit price, summed from the rows above"
          />
          <CardBody>
            <p className="text-lg font-semibold tabular-nums text-text">
              {formatMoneyString(
                rows.reduce(
                  (carry, row) => carry + Number(row.remaining_value ?? 0),
                  0
                )
              )}
            </p>
            <p className="mt-1 text-xs text-text-subtle">
              {rows.length} line{rows.length === 1 ? '' : 's'} still to receive
              across {new Set(rows.map((row) => row.purchase_order_id)).size}{' '}
              purchase order
              {new Set(rows.map((row) => row.purchase_order_id)).size === 1
                ? ''
                : 's'}
              .
            </p>
          </CardBody>
        </Card>
      )}
    </div>
  );
}