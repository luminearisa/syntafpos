import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { cn, initials } from '@/utils/format';
import { useAuthStore } from '@/stores/auth-store';
import { ContextSwitcher, useSwitcherOptions } from './context-switcher';

interface TopbarProps {
  collapsed: boolean;
  onToggleCollapsed: () => void;
  onOpenMobile: () => void;
}

export function Topbar({
  collapsed,
  onToggleCollapsed,
  onOpenMobile,
}: TopbarProps) {
  const { user, setScope, logout } = useAuthStore();
  const { companies, branches, warehouses, registers } = useSwitcherOptions();

  return (
    <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-border bg-surface/95 px-3 backdrop-blur">
      <button
        type="button"
        onClick={onOpenMobile}
        className="flex h-8 w-8 items-center justify-center rounded-md text-text-muted hover:bg-surface-alt lg:hidden"
        aria-label="Open navigation menu"
      >
        <ion-icon name="menu-outline" aria-hidden="true" />
      </button>

      <button
        type="button"
        onClick={onToggleCollapsed}
        className={cn(
          'hidden h-8 w-8 items-center justify-center rounded-md text-text-muted',
          'hover:bg-surface-alt hover:text-text lg:flex'
        )}
        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
      >
        <ion-icon
          name={collapsed ? 'chevron-forward-outline' : 'chevron-back-outline'}
          aria-hidden="true"
        />
      </button>

      <div className="flex flex-1 items-center gap-1 overflow-x-auto">
        <ContextSwitcher
          scope="company"
          icon="business-outline"
          placeholder="No company"
          options={companies}
          onSelect={(id) => setScope({ companyId: id })}
        />

        <span className="text-text-subtle">/</span>

        <ContextSwitcher
          scope="branch"
          icon="storefront-outline"
          placeholder="All branches"
          options={branches}
          disabled={companies.length === 0}
          onSelect={(id) => setScope({ branchId: id })}
        />

        <span className="hidden text-text-subtle sm:inline">/</span>

        <div className="hidden items-center gap-1 sm:flex">
          <ContextSwitcher
            scope="warehouse"
            icon="cube-outline"
            placeholder="All warehouses"
            options={warehouses}
            disabled={companies.length === 0}
            onSelect={(id) => setScope({ warehouseId: id })}
          />

          <span className="text-text-subtle">/</span>

          <ContextSwitcher
            scope="register"
            icon="cash-outline"
            placeholder="All registers"
            options={registers}
            disabled={companies.length === 0}
            onSelect={(id) => setScope({ registerId: id })}
          />
        </div>
      </div>

      <UserMenu user={user} logout={logout} />
    </header>
  );
}

function UserMenu({
  user,
  logout,
}: {
  user: ReturnType<typeof useAuthStore.getState>['user'];
  logout: () => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (!open) {
      return;
    }

    const handleClick = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClick);

    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  if (!user) {
    return null;
  }

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div ref={containerRef} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-surface-alt focus-visible:outline-2 focus-visible:outline-primary"
        aria-label="Account menu"
      >
        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary">
          {initials(user.name)}
        </span>
        <span className="hidden text-left sm:block">
          <span className="block max-w-[8rem] truncate text-xs font-medium text-text">
            {user.name}
          </span>
          <span className="block max-w-[8rem] truncate text-[10px] text-text-subtle">
            {user.email}
          </span>
        </span>
        <ion-icon
          name="chevron-down-outline"
          class="text-text-subtle"
          aria-hidden="true"
        />
      </button>

      {open && (
        <div className="absolute right-0 top-full z-50 mt-1 w-56 rounded-md border border-border bg-surface shadow-lg">
          <div className="border-b border-border px-3 py-2">
            <p className="text-xs font-semibold text-text">{user.name}</p>
            <p className="truncate text-[11px] text-text-muted">{user.email}</p>
            {user.roles && user.roles.length > 0 && (
              <p className="mt-1 truncate text-[10px] text-text-subtle">
                {user.roles.map((role) => role.display_name).join(', ')}
              </p>
            )}
          </div>

          <div className="flex flex-col py-1">
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                navigate('/profile');
              }}
              className="flex items-center gap-2 px-3 py-2 text-left text-xs text-text hover:bg-surface-alt"
            >
              <ion-icon name="person-outline" aria-hidden="true" />
              My profile
            </button>
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                navigate('/profile/password');
              }}
              className="flex items-center gap-2 px-3 py-2 text-left text-xs text-text hover:bg-surface-alt"
            >
              <ion-icon name="lock-closed-outline" aria-hidden="true" />
              Change password
            </button>
            <button
              type="button"
              onClick={handleLogout}
              className="flex items-center gap-2 px-3 py-2 text-left text-xs text-danger hover:bg-danger-soft"
            >
              <ion-icon name="log-out-outline" aria-hidden="true" />
              Sign out
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
