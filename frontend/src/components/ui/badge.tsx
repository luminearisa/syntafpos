import { type HTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/utils/format';

type Variant =
  | 'default'
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'outline';

const variantClasses: Record<Variant, string> = {
  default: 'bg-surface-alt text-text-muted border-border',
  primary: 'bg-primary-soft text-primary border-primary/30',
  success: 'bg-success-soft text-success border-success/30',
  warning: 'bg-warning-soft text-warning border-warning/30',
  danger: 'bg-danger-soft text-danger border-danger/30',
  info: 'bg-info-soft text-info border-info/30',
  outline: 'bg-transparent text-text-muted border-border',
};

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: Variant;
  icon?: string;
  children: ReactNode;
}

export function Badge({
  className,
  variant = 'default',
  icon,
  children,
  ...props
}: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
        variantClasses[variant],
        className
      )}
      {...props}
    >
      {icon && <ion-icon name={icon} class="text-[0.9em]" aria-hidden="true" />}
      {children}
    </span>
  );
}

export function StatusBadge({ status }: { status: string | null | undefined }) {
  if (!status) {
    return <Badge variant="outline">-</Badge>;
  }

  const variant: Variant =
    status === 'active'
      ? 'success'
      : status === 'inactive'
        ? 'default'
        : status === 'suspended'
          ? 'danger'
          : 'warning';

  return (
    <Badge variant={variant} icon={status === 'active' ? 'checkmark-circle' : 'ellipse-outline'}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </Badge>
  );
}
