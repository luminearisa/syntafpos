import { type InputHTMLAttributes, type ReactNode, forwardRef } from 'react';
import { cn } from '@/utils/format';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  icon?: string;
  wrapperClassName?: string;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  (
    { className, label, error, hint, icon, wrapperClassName, id, ...props },
    ref
  ) => {
    const inputId = id ?? props.name;

    return (
      <div className={cn('flex flex-col gap-1', wrapperClassName)}>
        {label && (
          <label
            htmlFor={inputId}
            className="text-xs font-medium text-text-muted"
          >
            {label}
          </label>
        )}

        <div className="relative">
          {icon && (
            <ion-icon
              name={icon}
              class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-base text-text-subtle"
              aria-hidden="true"
            />
          )}

          <input
            ref={ref}
            id={inputId}
            className={cn(
              'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text shadow-xs transition-[border-color,box-shadow] duration-150',
              'placeholder:text-text-subtle',
              'focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
              'disabled:cursor-not-allowed disabled:bg-surface-alt disabled:opacity-60',
              icon && 'pl-9',
              error && 'border-danger focus:border-danger focus:ring-danger',
              className
            )}
            aria-invalid={!!error}
            {...props}
          />
        </div>

        {error ? (
          <span className="flex items-center gap-1 text-xs text-danger">
            <ion-icon name="alert-circle-outline" aria-hidden="true" />
            {error}
          </span>
        ) : (
          hint && <span className="text-xs text-text-subtle">{hint}</span>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

export interface SelectProps
  extends React.SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  error?: string;
  options: { label: string; value: string | number }[];
  wrapperClassName?: string;
  placeholder?: string;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  (
    {
      className,
      label,
      error,
      options,
      wrapperClassName,
      placeholder,
      id,
      ...props
    },
    ref
  ) => {
    const selectId = id ?? props.name;

    return (
      <div className={cn('flex flex-col gap-1', wrapperClassName)}>
        {label && (
          <label
            htmlFor={selectId}
            className="text-xs font-medium text-text-muted"
          >
            {label}
          </label>
        )}

        <select
          ref={ref}
          id={selectId}
          className={cn(
            'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text shadow-xs transition-[border-color,box-shadow] duration-150',
            'focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
            'disabled:cursor-not-allowed disabled:bg-surface-alt',
            error && 'border-danger focus:border-danger focus:ring-danger',
            className
          )}
          {...props}
        >
          {placeholder && (
            <option value="">{placeholder}</option>
          )}

          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        {error && (
          <span className="flex items-center gap-1 text-xs text-danger">
            <ion-icon name="alert-circle-outline" aria-hidden="true" />
            {error}
          </span>
        )}
      </div>
    );
  }
);

Select.displayName = 'Select';

export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  wrapperClassName?: string;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, label, error, wrapperClassName, id, ...props }, ref) => {
    const textareaId = id ?? props.name;

    return (
      <div className={cn('flex flex-col gap-1', wrapperClassName)}>
        {label && (
          <label
            htmlFor={textareaId}
            className="text-xs font-medium text-text-muted"
          >
            {label}
          </label>
        )}

        <textarea
          ref={ref}
          id={textareaId}
          className={cn(
            'min-h-[88px] w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-text shadow-xs transition-[border-color,box-shadow] duration-150',
            'placeholder:text-text-subtle',
            'focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
            error && 'border-danger focus:border-danger focus:ring-danger',
            className
          )}
          {...props}
        />

        {error && (
          <span className="flex items-center gap-1 text-xs text-danger">
            <ion-icon name="alert-circle-outline" aria-hidden="true" />
            {error}
          </span>
        )}
      </div>
    );
  }
);

Textarea.displayName = 'Textarea';

export function FieldGroup({
  title,
  children,
  className,
}: {
  title: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'rounded-xl border border-border/80 bg-surface p-4 shadow-xs',
        className
      )}
    >
      <h3 className="mb-3 text-sm font-semibold text-text">{title}</h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {children}
      </div>
    </div>
  );
}
