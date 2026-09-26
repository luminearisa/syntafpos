import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';

import { useAuthStore } from '@/stores/auth-store';
import { dashboardApi } from '@/api/services';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { listQueryKeys } from '@/lib/query-client';
import type { DashboardData } from '@/types';
import { DashboardSkeleton, DashboardWidgetCard } from './dashboard-widget';
import { QuickActions } from './quick-actions';

const WIDGET_ICONS: Record<string, string> = {
  total_sales: 'cart-outline',
  total_transactions: 'receipt-outline',
  gross_profit: 'trending-up-outline',
  expenses: 'cash-outline',
  net_profit: 'wallet-outline',
  low_stock: 'alert-circle-outline',
  outstanding_receivable: 'arrow-redo-outline',
  outstanding_payable: 'arrow-undo-outline',
};

interface GlanceStat {
  key: keyof DashboardData['counts'];
  label: string;
  icon: string;
}

const GLANCE_STATS: readonly GlanceStat[] = [
  { key: 'companies', label: 'Companies', icon: 'business-outline' },
  { key: 'branches', label: 'Branches', icon: 'storefront-outline' },
  { key: 'warehouses', label: 'Warehouses', icon: 'cube-outline' },
  { key: 'registers', label: 'Registers', icon: 'calculator-outline' },
  { key: 'users', label: 'Users', icon: 'people-outline' },
];

function humanizeChartKey(key: string): string {
  return key
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

interface ChartBar {
  label: string;
  value: number;
}

/**
 * The backend chart contract is intentionally shape-agnostic in Phase 1, so
 * each point is narrowed to a `{ label, value }` bar. Points that do not match
 * degrade to empty bars rather than fabricating numbers.
 */
function toChartBars(data: Array<Record<string, unknown>>): ChartBar[] {
  return data.map((entry) => {
    const rawLabel = entry['label'];
    const label =
      typeof rawLabel === 'string' || typeof rawLabel === 'number'
        ? String(rawLabel)
        : '';

    const rawValue = entry['value'];
    const value =
      typeof rawValue === 'number' && Number.isFinite(rawValue) ? rawValue : 0;

    return { label, value };
  });
}

function BusinessGlance({ counts }: { counts: DashboardData['counts'] }) {
  return (
    <Card>
      <CardHeader
        title="Business at a glance"
        description="Structural counts across your access scope"
      />
      <CardBody>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          {GLANCE_STATS.map((stat) => (
            <div
              key={stat.key}
              className="flex items-center gap-2.5 rounded-md border border-border bg-surface-alt/40 p-2.5"
            >
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-surface text-text-muted">
                <ion-icon
                  name={stat.icon}
                  class="text-lg"
                  aria-hidden="true"
                />
              </span>
              <div className="min-w-0">
                <p className="text-base font-semibold tabular-nums text-text">
                  {counts[stat.key]}
                </p>
                <p className="truncate text-[11px] text-text-muted">
                  {stat.label}
                </p>
              </div>
            </div>
          ))}
        </div>
      </CardBody>
    </Card>
  );
}

function ChartsCard({
  charts,
}: {
  charts: DashboardData['charts'];
}) {
  const entries = Object.entries(charts);

  return (
    <Card>
      <CardHeader
        title="Charts"
        description="Live charts appear here once their source modules are live"
      />
      <CardBody>
        {entries.length === 0 ? (
          <p className="text-xs text-text-subtle">No charts configured.</p>
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {entries.map(([key, chart]) => {
              const bars = chart.available ? toChartBars(chart.data) : [];
              const maxValue = Math.max(...bars.map((bar) => bar.value), 1);

              return (
                <div
                  key={key}
                  className="rounded-md border border-border bg-surface-alt/40 p-3"
                >
                  <div className="mb-2 flex items-center justify-between gap-2">
                    <h3 className="truncate text-sm font-medium text-text">
                      {humanizeChartKey(key)}
                    </h3>

                    {!chart.available && (
                      <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-info-soft px-1.5 py-0.5 text-[10px] font-medium text-info">
                        <ion-icon
                          name="hourglass-outline"
                          class="text-[11px]"
                          aria-hidden="true"
                        />
                        Pending
                      </span>
                    )}
                  </div>

                  {bars.length > 0 ? (
                    <ul className="flex flex-col gap-1.5">
                      {bars.map((bar, index) => (
                        <li
                          key={`${bar.label}-${index}`}
                          className="flex items-center gap-2"
                        >
                          <span className="w-20 shrink-0 truncate text-[11px] text-text-muted">
                            {bar.label}
                          </span>
                          <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-alt">
                            <span
                              className="block h-full rounded-full bg-primary"
                              style={{
                                width: `${(bar.value / maxValue) * 100}%`,
                              }}
                            />
                          </span>
                          <span className="w-12 shrink-0 text-right text-[11px] tabular-nums text-text">
                            {bar.value}
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-[11px] leading-snug text-text-subtle">
                      {chart.available
                        ? 'No data points returned for this chart yet.'
                        : 'Data source module is not live yet — the chart renders real data once available.'}
                    </p>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </CardBody>
    </Card>
  );
}

export default function DashboardPage() {
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: listQueryKeys.dashboard,
    queryFn: dashboardApi.index,
  });

  if (isLoading) {
    return <DashboardSkeleton />;
  }

  // An empty envelope means the request succeeded but carried no payload.
  if (error || !data) {
    return (
      <ErrorState
        message="Unable to load dashboard data"
        onRetry={() => refetch()}
        className="py-20"
      />
    );
  }

  const { context, widgets, charts, counts } = data.data;
  const company = context.company;

  const firstName = user?.name.trim().split(/\s+/)[0] ?? 'there';
  const today = new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  }).format(new Date());

  return (
    <div className="flex flex-col gap-5 sm:gap-6">
      <section className="relative isolate overflow-hidden rounded-2xl bg-[linear-gradient(120deg,#14243d_0%,#1e3760_58%,#3158c9_100%)] px-5 py-5 text-white shadow-md sm:px-7 sm:py-6">
        <div className="pointer-events-none absolute -right-12 -top-20 h-64 w-64 rounded-full border border-white/10" />
        <div className="pointer-events-none absolute -right-2 -top-9 h-44 w-44 rounded-full bg-white/[0.06] blur-2xl" />
        <div className="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            <div className="mb-2 flex items-center gap-2">
              <span className="inline-flex h-6 items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-2.5 text-[10px] font-bold tracking-[0.12em] text-blue-100 uppercase">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
                Business overview
              </span>
            </div>
            <h1 className="text-2xl font-bold tracking-[-0.04em] sm:text-[30px]">
              Welcome back, {firstName}
            </h1>
            <p className="mt-1.5 max-w-2xl text-[13px] leading-relaxed text-blue-100/80">
              {company
                ? `Here is a clear view of ${company.name} and your retail operations.`
                : 'Choose a company to see the latest overview of your retail operations.'}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2.5 rounded-xl border border-white/10 bg-white/[0.08] px-3 py-2.5 text-xs text-blue-50">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
              <ion-icon name="calendar-outline" class="text-lg" aria-hidden="true" />
            </span>
            <span>
              <span className="block text-[10px] font-medium text-blue-100/70">TODAY</span>
              <span className="block font-semibold">{today}</span>
            </span>
          </div>
        </div>
      </section>

      <QuickActions />

      {company ? (
        <section>
          <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">
            Insights
          </h2>
          {widgets.length === 0 ? (
            <Card>
              <EmptyState
                icon="stats-chart-outline"
                title="No insights configured"
                description="Metric widgets will appear here once the reporting modules are live."
              />
            </Card>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              {widgets.map((widget) => (
                <DashboardWidgetCard
                  key={widget.key}
                  widget={widget}
                  icon={WIDGET_ICONS[widget.key] ?? 'stats-chart-outline'}
                />
              ))}
            </div>
          )}
        </section>
      ) : (
        <Card>
          <EmptyState
            icon="business-outline"
            title="No company selected"
            description="Select an existing company or create a new one to start seeing business insights."
            action={{
              label: 'Browse companies',
              icon: 'arrow-forward-outline',
              onClick: () => navigate('/companies'),
            }}
          />
        </Card>
      )}

      <BusinessGlance counts={counts} />

      {company && <ChartsCard charts={charts} />}
    </div>
  );
}
