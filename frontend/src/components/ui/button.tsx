import { type ButtonHTMLAttributes, forwardRef } from 'react';
import { cn } from '@/utils/format';

type Variant =
  | 'primary'
  | 'secondary'
  | 'outline'
  | 'ghost'
  | 'danger'
  | 'success';

type Size = 'xs' | 'sm' | 'md' | 'lg';

export interface ButtonProps
  extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'size'> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: string;
}

const variantClasses: Record<Variant, string> = {
  primary:
    'border border-primary bg-primary text-white shadow-sm hover:border-primary-dark hover:bg-primary-dark active:bg-primary-dark',
  secondary:
    'border border-border bg-surface text-text shadow-xs hover:border-border-strong hover:bg-surface-alt',
  outline:
    'border border-primary/30 bg-primary-soft/60 text-primary hover:border-primary/50 hover:bg-primary-soft',
  ghost: 'border border-transparent bg-transparent text-text-muted hover:bg-surface-alt hover:text-text',
  danger:
    'border border-transparent bg-danger text-white shadow-xs hover:brightness-95',
  success:
    'border border-transparent bg-success text-white shadow-xs hover:brightness-95',
};

const sizeClasses: Record<Size, string> = {
  xs: 'h-7 gap-1 px-2 text-xs',
  sm: 'h-9 gap-1.5 px-3 text-xs',
  md: 'h-10 gap-2 px-4 text-sm',
  lg: 'h-11 gap-2 px-5 text-sm',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    { className, variant = 'primary', size = 'md', loading, icon, children, disabled, ...props },
    ref
  ) => {
    return (
      <button
        ref={ref}
        className={cn(
          'inline-flex shrink-0 items-center justify-center rounded-lg font-semibold tracking-[-0.01em]',
          'transition-all duration-150 active:scale-[0.98]',
          'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
          'disabled:pointer-events-none disabled:opacity-50',
          variantClasses[variant],
          sizeClasses[size],
          className
        )}
        disabled={disabled || loading}
        aria-busy={loading || undefined}
        {...props}
      >
        {loading ? (
          <ion-icon
            name="sync-outline"
            class="animate-spin text-current"
            aria-hidden="true"
          />
        ) : (
          icon && <ion-icon name={icon} class="text-[1.1em]" aria-hidden="true" />
        )}
        {children}
      </button>
    );
  }
);

Button.displayName = 'Button';
