import type { DashboardWidget } from '@/types';
import { cn, formatMoney } from '@/utils/format';
import { Skeleton } from '@/components/ui/state';

const NUMBER_FORMATTER = new Intl.NumberFormat('id-ID');

/**
 * Monetary widgets carry a `currency`, unit counters (e.g. low-stock units) do
 * not. formatMoney always prefixes the "Rp" symbol, so non-monetary values
 * bypass it — otherwise a unit count of 5 would read "Rp 5".
 */
function formatWidgetValue(widget: DashboardWidget): string {
  if (widget.currency) {
    return formatMoney(widget.value, widget.currency);
  }

  return NUMBER_FORMATTER.format(widget.value);
}

export function DashboardWidgetCard({
  widget,
  icon = 'stats-chart-outline',
}: {
  widget: DashboardWidget;
  icon?: string;
}) {
  // Phase 1 ships no transaction modules: an unavailable widget must never
  // imply a real figure, so it renders a dash instead of its zero value.
  const pending = !widget.available;

  return (
    <div className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-3 shadow-xs">
      <div className="flex items-center justify-between gap-2">
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-surface-alt text-text-muted">
          <ion-icon name={icon} class="text-base" aria-hidden="true" />
        </span>

        {pending && (
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

      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-text-muted">
          {widget.label}
        </p>
        <p
          className={cn(
            'mt-0.5 text-lg font-semibold tabular-nums',
            pending ? 'text-text-subtle' : 'text-text'
          )}
        >
          {pending ? '—' : formatWidgetValue(widget)}
        </p>
      </div>

      {pending && (
        <p className="text-[11px] leading-snug text-text-subtle">
          No data yet — module available in a later phase
        </p>
      )}
    </div>
  );
}

export function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true" role="status">
      <div className="flex flex-col gap-2">
        <Skeleton className="h-6 w-32" />
        <Skeleton className="h-4 w-64" />
      </div>

      <div className="flex flex-wrap gap-2">
        {Array.from({ length: 5 }).map((_, index) => (
          <Skeleton key={index} className="h-8 w-28" />
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {Array.from({ length: 8 }).map((_, index) => (
          <Skeleton key={index} className="h-24" />
        ))}
      </div>

      <Skeleton className="h-32" />
      <Skeleton className="h-56" />
    </div>
  );
}
