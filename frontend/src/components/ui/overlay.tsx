import {
  type ComponentPropsWithoutRef,
  type ReactNode,
  createContext,
  useContext,
  useEffect,
  useRef,
  useState,
} from 'react';
import { cn } from '@/utils/format';
import { Button } from './button';

interface ModalContextValue {
  open: boolean;
  close: () => void;
}

const ModalContext = createContext<ModalContextValue | null>(null);

function useModalContext(): ModalContextValue {
  const context = useContext(ModalContext);

  if (!context) {
    throw new Error('Modal components must be rendered inside <Modal>');
  }

  return context;
}

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  closeOnBackdrop?: boolean;
}

const sizeClasses = {
  sm: 'max-w-md',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  closeOnBackdrop = true,
}: ModalProps) {
  const [render, setRender] = useState(open);

  useEffect(() => {
    if (open) {
      setRender(true);
    }
  }, [open]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleEscape);
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', handleEscape);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!render) {
    return null;
  }

  return (
    <ModalContext.Provider value={{ open, close: onClose }}>
      <div
        className={cn(
          'fixed inset-0 z-50 flex items-center justify-center p-4',
          'transition-opacity',
          open ? 'opacity-100' : 'opacity-0'
        )}
        onTransitionEnd={() => {
          if (!open) {
            setRender(false);
          }
        }}
      >
        <div
          className="absolute inset-0 bg-slate-950/55 backdrop-blur-sm"
          onClick={() => closeOnBackdrop && onClose()}
          aria-hidden="true"
        />

        <div
          role="dialog"
          aria-modal="true"
          className={cn(
            'relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-2xl border border-border/70 bg-surface shadow-lg',
            sizeClasses[size]
          )}
        >
          {(title || description) && (
            <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
              <div className="min-w-0">
                {title && (
                  <h2 className="text-sm font-semibold text-text">{title}</h2>
                )}
                {description && (
                  <p className="mt-0.5 text-xs text-text-muted">{description}</p>
                )}
              </div>

              <Button
                variant="ghost"
                size="sm"
                icon="close-outline"
                onClick={onClose}
                aria-label="Close dialog"
                className="-mr-2 shrink-0"
              />
            </div>
          )}

          <div className="flex-1 overflow-y-auto p-4">{children}</div>

          {footer && (
            <div className="flex items-center justify-end gap-2 border-t border-border bg-surface-alt px-4 py-3">
              {footer}
            </div>
          )}
        </div>
      </div>
    </ModalContext.Provider>
  );
}

interface ConfirmModalProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: 'danger' | 'primary';
  loading?: boolean;
}

export function ConfirmModal({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  variant = 'danger',
  loading = false,
}: ConfirmModalProps) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={loading}>
            {cancelLabel}
          </Button>
          <Button
            variant={variant}
            size="sm"
            onClick={onConfirm}
            loading={loading}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex gap-3">
        <span
          className={cn(
            'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
            variant === 'danger' ? 'bg-danger-soft text-danger' : 'bg-primary-soft text-primary'
          )}
        >
          <ion-icon
            name={variant === 'danger' ? 'warning-outline' : 'information-circle-outline'}
            class="text-lg"
            aria-hidden="true"
          />
        </span>

        <div className="text-sm text-text-muted">{message}</div>
      </div>
    </Modal>
  );
}

interface DrawerProps {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  side?: 'left' | 'right';
  width?: string;
}

export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  side = 'right',
  width = 'max-w-md',
}: DrawerProps) {
  const [render, setRender] = useState(open);
  const previousOverflow = useRef('');

  useEffect(() => {
    if (open) {
      setRender(true);
      previousOverflow.current = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.body.style.overflow = previousOverflow.current;
    };
  }, [open]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleEscape);

    return () => document.removeEventListener('keydown', handleEscape);
  }, [open, onClose]);

  if (!render) {
    return null;
  }

  return (
    <ModalContext.Provider value={{ open, close: onClose }}>
      <div className="fixed inset-0 z-50">
        <div
          className="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]"
          onClick={onClose}
          aria-hidden="true"
        />

        <div
          role="dialog"
          aria-modal="true"
          className={cn(
            'absolute inset-y-0 flex w-full flex-col bg-surface shadow-lg transition-transform',
            width,
            side === 'right' ? 'right-0' : 'left-0',
            open
              ? 'translate-x-0'
              : side === 'right'
                ? 'translate-x-full'
                : '-translate-x-full'
          )}
          onTransitionEnd={() => {
            if (!open) {
              setRender(false);
            }
          }}
        >
          {(title || description) && (
            <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
              <div className="min-w-0">
                {title && (
                  <h2 className="text-sm font-semibold text-text">{title}</h2>
                )}
                {description && (
                  <p className="mt-0.5 text-xs text-text-muted">{description}</p>
                )}
              </div>

              <Button
                variant="ghost"
                size="sm"
                icon="close-outline"
                onClick={onClose}
                aria-label="Close panel"
                className="-mr-2 shrink-0"
              />
            </div>
          )}

          <div className="flex-1 overflow-y-auto p-4">{children}</div>

          {footer && (
            <div className="flex items-center justify-end gap-2 border-t border-border bg-surface-alt px-4 py-3">
              {footer}
            </div>
          )}
        </div>
      </div>
    </ModalContext.Provider>
  );
}

/**
 * Lightweight tooltip on hover/focus; no positioning library required.
 */
export function Tooltip({
  content,
  children,
  side = 'top',
}: {
  content: ReactNode;
  children: ReactNode;
  side?: 'top' | 'bottom' | 'left' | 'right';
}) {
  const positionClasses: Record<string, string> = {
    top: 'bottom-full left-1/2 mb-1 -translate-x-1/2',
    bottom: 'top-full left-1/2 mt-1 -translate-x-1/2',
    left: 'right-full top-1/2 mr-1 -translate-y-1/2',
    right: 'left-full top-1/2 ml-1 -translate-y-1/2',
  };

  return (
    <span className="group relative inline-flex">
      {children}
      <span
        role="tooltip"
        className={cn(
          'pointer-events-none absolute z-50 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white',
          'opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100',
          positionClasses[side]
        )}
      >
        {content}
      </span>
    </span>
  );
}

export function IconButton({
  icon,
  label,
  className,
  ...props
}: ComponentPropsWithoutRef<'button'> & { icon: string; label: string }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={cn(
        'flex h-8 w-8 items-center justify-center rounded-md text-text-muted',
        'hover:bg-surface-alt hover:text-text',
        'focus-visible:outline-2 focus-visible:outline-primary',
        className
      )}
      {...props}
    >
      <ion-icon name={icon} class="text-lg" aria-hidden="true" />
    </button>
  );
}

export { useModalContext };
