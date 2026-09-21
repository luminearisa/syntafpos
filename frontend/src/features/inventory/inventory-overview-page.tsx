import { useQuery } from '@tanstack/react-query';
import { inventoryReportApi } from '@/api/services';
import { useAuthStore } from '@/stores/auth-store';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, PageHeader, Skeleton } from '@/components/ui/state';
import { listQueryKeys } from '@/lib/query-client';
import { cn, formatDecimal, formatMoneyString } from '@/utils/format';

interface StatCardProps {
  label: string;
  value: string;
  icon: string;
  /**
   * A metric without a backing total endpoint is shown as unavailable rather
   * than sampled or invented (spec §51): a one-row page cannot be summed into a
   * grand total, so the card admits that instead of displaying a partial figure.
   */
  pending?: boolean;
  note?: string;
}

function StatCard({ label, value, icon, pending, note }: StatCardProps) {
  return (
    <Card className="flex flex-col gap-2 p-4">
      <div className="flex items-center justify-between gap-2">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-surface-alt text-text-muted">
          <ion-icon name={icon} class="text-lg" aria-hidden="true" />
        </span>

        {pending && (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-info-soft px-1.5 py-0.5 text-[10px] font-medium text-info">
            <ion-icon name="hourglass-outline" class="text-[11px]" aria-hidden="true" />
            No totals yet
          </span>
        )}
      </div>

      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-text-muted">{label}</p>
        <p
          className={cn(
            'mt-0.5 text-lg font-semibold tabular-nums',
            pending ? 'text-text-subtle' : 'text-text'
          )}
        >
          {pending ? '—' : value}
        </p>
      </div>

      {note && <p className="text-[11px] leading-snug text-text-subtle">{note}</p>}
    </Card>
  );
}

function OverviewSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true" role="status">
      <div className="flex flex-col gap-2">
        <Skeleton className="h-6 w-40" />
        <Skeleton className="h-4 w-72" />
      </div>
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {Array.from({ length: 5 }).map((_, index) => (
          <Skeleton key={index} className="h-28" />
        ))}
      </div>
    </div>
  );
}

/**
 * Inventory overview (spec §40): a dashboard of cards summarising the stock
 * position for the active company.
 *
 * Every figure is read straight from a report endpoint — the summary and
 * low-stock pagination totals and the valuation grand total — so an empty
 * warehouse renders real zeros and never a demo figure (§51).
 */
export default function InventoryOverviewPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const companyName = useAuthStore(
    (state) => state.user?.companies?.find((company) => company.id === state.scope.companyId)?.name
  );
  const enabled = companyId !== null;

  const summary = useQuery({
    queryKey: [listQueryKeys.reports, 'overview', 'stock-summary', companyId],
    queryFn: () =>
      inventoryReportApi.stockSummary({ company_id: companyId ?? undefined, per_page: 1 }),
    enabled,
  });

  const lowStock = useQuery({
    queryKey: [listQueryKeys.reports, 'overview', 'low-stock', companyId],
    queryFn: () => inventoryReportApi.lowStock({ company_id: companyId ?? undefined, per_page: 1 }),
    enabled,
  });

  const valuation = useQuery({
    queryKey: [listQueryKeys.reports, 'overview', 'stock-valuation', companyId],
    queryFn: () =>
      inventoryReportApi.stockValuation({ company_id: companyId ?? undefined, per_page: 1 }),
    enabled,
  });

  if (!enabled) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="Inventory overview"
          description="Stock position at a glance for the active company."
        />
        <Card>
          <EmptyState
            icon="business-outline"
            title="No company selected"
            description="Select a company in the topbar to see its inventory position."
          />
        </Card>
      </div>
    );
  }

  const isLoading = summary.isLoading || lowStock.isLoading || valuation.isLoading;
  const error = summary.error ?? lowStock.error ?? valuation.error;

  if (isLoading) {
    return <OverviewSkeleton />;
  }

  if (error) {
    return (
      <ErrorState
        message="Unable to load the inventory overview"
        onRetry={() => {
          summary.refetch();
          lowStock.refetch();
          valuation.refetch();
        }}
        className="py-20"
      />
    );
  }

  // meta.total is the count of balance rows the reports see; an empty warehouse
  // reports zero rows, which is the real figure.
  const totalProducts = (summary.data?.meta?.total as number | undefined) ?? 0;
  const lowStockCount = (lowStock.data?.meta?.total as number | undefined) ?? 0;
  const stockValue = valuation.data?.data.total ?? '0';

  const stats: StatCardProps[] = [
    {
      label: 'Total Products',
      value: formatDecimal(totalProducts, 0),
      icon: 'cube-outline',
      note: 'Stocked product balances across warehouses',
    },
    {
      label: 'Total Stock Units',
      value: '',
      icon: 'layers-outline',
      pending: true,
      note: 'No grand-total endpoint yet — units are summed per row on the reports.',
    },
    {
      label: 'Stock Value',
      value: formatMoneyString(stockValue),
      icon: 'cash-outline',
      note: 'On hand at weighted average cost',
    },
    {
      label: 'Low Stock',
      value: formatDecimal(lowStockCount, 0),
      icon: 'alert-circle-outline',
      note: 'Products at or below their reorder point',
    },
    {
      label: 'Out of Stock',
      value: '',
      icon: 'close-circle-outline',
      pending: true,
      note: 'No zero-stock count endpoint yet — find them on the low-stock report.',
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Inventory overview"
        description={
          companyName
            ? `${companyName} — stock position at a glance`
            : 'Stock position at a glance for the active company.'
        }
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {stats.map((stat) => (
          <StatCard key={stat.label} {...stat} />
        ))}
      </div>

      <Card>
        <EmptyState
          icon="receipt-outline"
          title="Reports are one click away"
          description="Open the stock movement ledger, the low-stock list or the valuation report for the full detail behind these figures."
        />
      </Card>
    </div>
  );
}
