import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { cn } from '@/utils/format';

type ToastVariant = 'success' | 'error' | 'warning' | 'info';

interface Toast {
  id: number;
  title?: string;
  message?: string;
  variant: ToastVariant;
}

interface ToastContextValue {
  toasts: Toast[];
  toast: (opts: { title?: string; message?: string; variant?: ToastVariant }) => void;
  dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const variantConfig: Record<ToastVariant, { icon: string; className: string }> = {
  success: { icon: 'checkmark-circle', className: 'text-success' },
  error: { icon: 'alert-circle', className: 'text-danger' },
  warning: { icon: 'warning', className: 'text-warning' },
  info: { icon: 'information-circle', className: 'text-info' },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  const toast = useCallback(
    ({ title, message, variant = 'info' }: { title?: string; message?: string; variant?: ToastVariant }) => {
      if (!title && !message) {
        return;
      }

      const id = Date.now() + Math.floor(Math.random() * 1000);

      setToasts((current) => [...current, { id, title, message, variant }]);

      window.setTimeout(() => dismiss(id), 4_000);
    },
    [dismiss]
  );

  return (
    <ToastContext.Provider value={{ toasts, toast, dismiss }}>
      {children}

      <div className="pointer-events-none fixed top-4 right-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
        {toasts.map((toastItem) => {
          const config = variantConfig[toastItem.variant];

          return (
            <div
              key={toastItem.id}
              role="status"
              className="pos-toast-in pointer-events-auto flex items-start gap-2.5 rounded-lg border border-border bg-surface p-3 shadow-md"
            >
              <ion-icon
                name={config.icon}
                class={cn('mt-0.5 text-lg shrink-0', config.className)}
                aria-hidden="true"
              />

              <div className="min-w-0 flex-1">
                {toastItem.title && (
                  <p className="text-sm font-medium text-text">{toastItem.title}</p>
                )}
                {toastItem.message && (
                  <p
                    className={cn(
                      'text-text',
                      toastItem.title ? 'mt-0.5 text-xs text-text-muted' : 'text-sm'
                    )}
                  >
                    {toastItem.message}
                  </p>
                )}
              </div>

              <button
                type="button"
                onClick={() => dismiss(toastItem.id)}
                aria-label="Dismiss notification"
                className="-mr-1 -mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded text-text-subtle hover:bg-surface-alt hover:text-text"
              >
                <ion-icon name="close" aria-hidden="true" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext);

  if (!context) {
    throw new Error('useToast must be used within a ToastProvider');
  }

  return context;
}
