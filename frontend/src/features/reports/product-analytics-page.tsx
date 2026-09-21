import { useEffect } from 'react';

import { productAnalyticsApi } from '@/api/services';
import type { ProductAnalyticsRow } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useListQuery } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { PageHeader } from '@/components/ui/state';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { ForbiddenState } from './report-helpers';
import { formatDate, formatDecimal, formatMoneyString } from '@/utils/format';

/**
 * Product analytics (spec §35): the read across stock, cost, price and
 * purchasing history per product.
 *
 * The fast / slow / dead-stock and profitability classifications are deferred
 * to a later phase, so `days_since_last_movement` is shown plainly as the
 * foundation field and no classification is computed here (§51). Every figure
 * is the API's own answer; money and quantities go through the formatters (§46).
 */

export default function ProductAnalyticsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);

  const list = useListQuery<ProductAnalyticsRow>(
    [...listQueryKeys.reports, 'product-analytics'],
    (params) => productAnalyticsApi.analytics(params),
    { company_id: companyId ?? undefined }
  );

  // Keep the report in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  if (!can('reports.inventory')) {
    return <ForbiddenState permission="reports.inventory" />;
  }

  const columns: Column<ProductAnalyticsRow>[] = [
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
      key: 'total_stock',
      header: 'Total Stock',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="font-medium tabular-nums">
          {formatDecimal(row.total_stock)}
        </span>
      ),
    },
    {
      key: 'current_cost',
      header: 'Current Cost',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.current_cost)}
        </span>
      ),
    },
    {
      key: 'current_price',
      header: 'Current Price',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums text-text-muted">
          {formatMoneyString(row.current_price)}
        </span>
      ),
    },
    {
      key: 'estimated_margin',
      header: 'Est. Margin',
      align: 'right',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col items-end">
          <span className="font-medium tabular-nums">
            {formatMoneyString(row.estimated_margin)}
          </span>
          <span className="text-xs text-text-subtle">
            {formatDecimal(row.estimated_margin_percent, 2)}%
          </span>
        </div>
      ),
    },
    {
      key: 'last_purchase_date',
      header: 'Last Purchase',
      sortable: true,
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-text-muted">
            {formatDate(row.last_purchase_date)}
          </span>
          {row.last_purchase_quantity && (
            <span className="text-xs text-text-subtle">
              {formatDecimal(row.last_purchase_quantity)} received
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'last_supplier_name',
      header: 'Last Supplier',
      render: (row) => (
        <span className="text-text-muted">
          {row.last_supplier_name ?? 'No purchases yet'}
        </span>
      ),
    },
    {
      key: 'movement_count',
      header: 'Stock Movements',
      align: 'right',
      sortable: true,
      render: (row) => (
        <span className="tabular-nums">{formatDecimal(row.movement_count)}</span>
      ),
    },
    {
      key: 'days_since_last_movement',
      header: 'Days Since Last Movement',
      align: 'right',
      sortable: true,
      render: (row) => {
        // The foundation field for the future fast / slow / dead-stock
        // classification. Rendered plainly, never classified client-side.
        if (row.days_since_last_movement === null) {
          return <span className="text-text-subtle">No movements yet</span>;
        }

        return (
          <span className="tabular-nums">
            {formatDecimal(row.days_since_last_movement, 0)}
          </span>
        );
      },
    },
  ];

  const errorMessage = list.error
    ? list.error.message || 'Failed to load the product analytics.'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Product Analytics"
        description="Stock, cost, price, estimated margin and movement recency per product."
      />

      <Card>
        <CardHeader
          title="What this report shows"
          description="The foundation for the stock-velocity classification"
        />
        <CardBody>
          <p className="text-xs leading-relaxed text-text-muted">
            Every row joins the stock ledger with pricing and purchasing
            history. The margin is the current price less the current cost, and{' '}
            <strong className="text-text">days since last movement</strong> is
            the field the fast, slow and dead-stock classification will be built
            on in a later phase — it is reported here exactly as computed, with
            no classification applied.
          </p>
        </CardBody>
      </Card>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.product_id}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search products..."
        emptyTitle="No product analytics yet"
        emptyDescription="A product appears here once it has stock, a cost or a price recorded in this company."
      />
    </div>
  );
}
