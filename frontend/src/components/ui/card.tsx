import { type HTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/utils/format';

export function Card({
  className,
  children,
  ...props
}: HTMLAttributes<HTMLDivElement> & { children: ReactNode }) {
  return (
    <div
      className={cn(
        'rounded-xl border border-border/80 bg-surface shadow-xs',
        className
      )}
      {...props}
    >
      {children}
    </div>
  );
}

export function CardHeader({
  title,
  description,
  action,
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex flex-col gap-2 border-b border-border/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
        className
      )}
    >
      <div className="min-w-0">
        <h3 className="truncate text-sm font-semibold tracking-[-0.01em] text-text">{title}</h3>
        {description && (
          <p className="mt-0.5 text-xs leading-relaxed text-text-muted">
            {description}
          </p>
        )}
      </div>

      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
}

export function CardBody({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return <div className={cn('p-3.5 sm:p-4', className)}>{children}</div>;
}

export function CardFooter({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return (
    <div
      className={cn(
        'flex flex-col items-stretch gap-2 border-t border-border/80 bg-surface-alt/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-end',
        className
      )}
    >
      {children}
    </div>
  );
}
