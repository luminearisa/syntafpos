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
    'bg-primary text-white hover:bg-primary-dark border border-transparent shadow-xs',
  secondary:
    'bg-surface text-text border border-border hover:bg-surface-alt shadow-xs',
  outline:
    'bg-transparent text-primary border border-primary hover:bg-primary-soft',
  ghost: 'bg-transparent text-text-muted hover:bg-surface-alt border-transparent',
  danger:
    'bg-danger text-white hover:opacity-90 border border-transparent shadow-xs',
  success:
    'bg-success text-white hover:opacity-90 border border-transparent shadow-xs',
};

const sizeClasses: Record<Size, string> = {
  xs: 'h-7 px-2 text-xs gap-1',
  sm: 'h-8 px-3 text-xs gap-1.5',
  md: 'h-9 px-4 text-sm gap-2',
  lg: 'h-10 px-5 text-sm gap-2',
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
          'inline-flex items-center justify-center rounded-md font-medium transition-colors',
          'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
          'disabled:cursor-not-allowed disabled:opacity-50',
          variantClasses[variant],
          sizeClasses[size],
          className
        )}
        disabled={disabled || loading}
        {...props}
      >
        {loading ? (
          <ion-icon
            name="sync-outline"
            class="animate-spin text-current"
            aria-hidden="true"
          />
        ) : (
          icon && (
            <ion-icon name={icon} class="text-[1.1em]" aria-hidden="true" />
          )
        )}
        {children}
      </button>
    );
  }
);

Button.displayName = 'Button';
