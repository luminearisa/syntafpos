import { type ReactNode } from 'react';
import { cn } from '@/utils/format';
import { Button } from './button';

interface EmptyStateProps {
  icon?: string;
  title: string;
  description?: string;
  action?: {
    label: string;
    onClick: () => void;
    icon?: string;
  };
  className?: string;
}

export function EmptyState({
  icon = 'file-tray-outline',
  title,
  description,
  action,
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-2.5 px-6 py-12 text-center',
        className
      )}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-soft text-primary ring-1 ring-primary/10">
        <ion-icon name={icon} class="text-2xl" aria-hidden="true" />
      </span>

      <h3 className="text-sm font-semibold text-text">{title}</h3>

      {description && (
        <p className="max-w-sm text-xs leading-relaxed text-text-muted">{description}</p>
      )}

      {action && (
        <Button
          variant="outline"
          size="sm"
          icon={action.icon}
          onClick={action.onClick}
          className="mt-1"
        >
          {action.label}
        </Button>
      )}
    </div>
  );
}

export function LoadingState({
  label = 'Loading...',
  className,
}: {
  label?: string;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex items-center justify-center gap-2.5 px-6 py-12 text-text-muted',
        className
      )}
      role="status"
      aria-live="polite"
    >
      <ion-icon
        name="sync-outline"
        class="animate-spin text-lg text-primary"
        aria-hidden="true"
      />
      <span className="text-sm">{label}</span>
    </div>
  );
}

export function ErrorState({
  message = 'Something went wrong',
  onRetry,
  className,
}: {
  message?: string;
  onRetry?: () => void;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-2.5 px-6 py-12 text-center',
        className
      )}
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-danger-soft text-danger ring-1 ring-danger/10">
        <ion-icon name="cloud-offline-outline" class="text-2xl" aria-hidden="true" />
      </span>

      <h3 className="max-w-md text-sm font-semibold text-text">{message}</h3>

      {onRetry && (
        <Button variant="outline" size="sm" icon="refresh" onClick={onRetry} className="mt-1">
          Try again
        </Button>
      )}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn('animate-pulse rounded-lg bg-surface-alt', className)}
      aria-hidden="true"
    />
  );
}

export function TableSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-2 p-4">
      {Array.from({ length: rows }).map((_, index) => (
        <Skeleton key={index} className="h-9 w-full" />
      ))}
    </div>
  );
}

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    <header className="flex flex-col gap-3 border-b border-border/80 pb-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h1 className="text-xl font-bold tracking-[-0.035em] text-text sm:text-2xl">{title}</h1>
        {description && (
          <p className="mt-1 max-w-3xl text-[13px] leading-relaxed text-text-muted">
            {description}
          </p>
        )}
      </div>

      {actions && (
        <div className="flex flex-wrap items-center gap-2 sm:justify-end">{actions}</div>
      )}
    </header>
  );
}
