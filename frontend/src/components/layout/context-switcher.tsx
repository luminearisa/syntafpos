import { useEffect, useRef, useState } from 'react';
import { cn } from '@/utils/format';
import { useAuthStore } from '@/stores/auth-store';
import type { Branch, Company, Register, Warehouse } from '@/types';

interface Option {
  id: number;
  label: string;
  sublabel?: string | null;
}

/**
 * Dropdown that swaps the active business context. The chosen ids are sent to
 * the API as X-Company-Id / X-Branch-Id / X-Warehouse-Id / X-Register-Id
 * headers by the API client, and are re-validated on the server side.
 */
export function ContextSwitcher({
  scope,
  options,
  onSelect,
  disabled,
  icon,
  placeholder,
  align = 'left',
}: {
  scope: 'company' | 'branch' | 'warehouse' | 'register';
  options: Option[];
  onSelect: (id: number | null) => void;
  disabled?: boolean;
  icon: string;
  placeholder: string;
  align?: 'left' | 'right';
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const active = options.find((option) => option.id === scopeValue(scope));

  useEffect(() => {
    if (!open) {
      return;
    }

    const handleClick = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClick);
    document.addEventListener('keydown', handleEscape);

    return () => {
      document.removeEventListener('mousedown', handleClick);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [open]);

  if (disabled || options.length === 0) {
    return (
      <div
        className="flex h-9 items-center gap-2 rounded-lg px-2.5 text-xs text-text-subtle"
        title={placeholder}
      >
        <ion-icon name={icon} aria-hidden="true" />
        <span className="max-w-[8rem] truncate">{placeholder}</span>
      </div>
    );
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className={cn(
          'flex h-9 max-w-[11rem] items-center gap-2 rounded-lg px-2.5 text-xs font-semibold text-text',
          'transition-colors hover:bg-surface-alt focus-visible:outline-2 focus-visible:outline-primary',
          open && 'bg-surface-alt'
        )}
      >
        <ion-icon name={icon} class="shrink-0 text-text-muted" aria-hidden="true" />
        <span className="truncate">{active?.label ?? placeholder}</span>
        <ion-icon
          name="chevron-down-outline"
          class="shrink-0 text-text-subtle"
          aria-hidden="true"
        />
      </button>

      {open && (
        <div
          role="listbox"
          className={cn(
            'absolute top-full z-50 mt-1 min-w-[14rem] max-w-[20rem] overflow-y-auto',
            'rounded-xl border border-border/80 bg-surface p-1 shadow-lg',
            align === 'right' ? 'right-0' : 'left-0'
          )}
        >
          <button
            type="button"
            onClick={() => {
              onSelect(null);
              setOpen(false);
            }}
            className="flex min-h-10 w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs text-text-muted transition-colors hover:bg-surface-alt"
          >
            <ion-icon name="ban-outline" aria-hidden="true" />
            All / not selected
          </button>

          {options.map((option) => (
            <button
              key={option.id}
              type="button"
              onClick={() => {
                onSelect(option.id);
                setOpen(false);
              }}
              className={cn(
                'flex min-h-10 w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs transition-colors hover:bg-surface-alt',
                option.id === active?.id
                  ? 'bg-primary-soft/70 font-semibold text-primary'
                  : 'text-text'
              )}
            >
              <ion-icon
                name={option.id === active?.id ? 'checkmark-circle' : 'ellipse-outline'}
                class="shrink-0"
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1">
                <span className="block truncate">{option.label}</span>
                {option.sublabel && (
                  <span className="block truncate text-[10px] text-text-subtle">
                    {option.sublabel}
                  </span>
                )}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function scopeValue(scope: 'company' | 'branch' | 'warehouse' | 'register'): number | null {
  const { scope: active } = useAuthStore.getState();

  switch (scope) {
    case 'company':
      return active.companyId;
    case 'branch':
      return active.branchId;
    case 'warehouse':
      return active.warehouseId;
    case 'register':
      return active.registerId;
  }
}

export function useSwitcherOptions() {
  const user = useAuthStore((state) => state.user);
  const companyId = useAuthStore((state) => state.scope.companyId);

  const companies: Option[] = (user?.companies ?? []).map((company: Company) => ({
    id: company.id,
    label: company.name,
    sublabel: company.code,
  }));

  const branches: Option[] = (user?.branches ?? [])
    .filter((branch: Branch) => branch.company_id === companyId)
    .map((branch) => ({
      id: branch.id,
      label: branch.name,
      sublabel: branch.code,
    }));

  const warehouses: Option[] = (user?.warehouses ?? [])
    .filter((warehouse: Warehouse) => warehouse.company_id === companyId)
    .map((warehouse) => ({
      id: warehouse.id,
      label: warehouse.name,
      sublabel: warehouse.code,
    }));

  const registers: Option[] = (user?.registers ?? [])
    .filter((item: Register) => item.company_id === companyId)
    .map((item) => ({
      id: item.id,
      label: item.name,
      sublabel: item.code,
    }));

  return { companies, branches, warehouses, registers };
}
