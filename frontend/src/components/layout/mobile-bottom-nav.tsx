import { NavLink, useLocation } from 'react-router-dom';
import { cn } from '@/utils/format';
import { useAuthStore } from '@/stores/auth-store';

interface MobileNavItem {
  label: string;
  to: string;
  icon: string;
  permission?: string;
}

const items: MobileNavItem[] = [
  { label: 'Home', to: '/dashboard', icon: 'home-outline' },
  { label: 'POS', to: '/pos', icon: 'calculator-outline', permission: 'pos.view' },
  { label: 'Products', to: '/products', icon: 'cube-outline', permission: 'products.view' },
  { label: 'Sales', to: '/sales', icon: 'receipt-outline', permission: 'sales.view' },
];

export function MobileBottomNav({ onOpenMore }: { onOpenMore: () => void }) {
  const can = useAuthStore((state) => state.can);
  const location = useLocation();
  const visibleItems = items.filter((item) => !item.permission || can(item.permission));
  const moreActive = !visibleItems.some(
    (item) => location.pathname === item.to || location.pathname.startsWith(`${item.to}/`)
  );

  return (
    <nav
      aria-label="Quick navigation"
      className="fixed inset-x-0 bottom-0 z-30 flex items-stretch border-t border-border/80 bg-white/95 px-2 pt-1 shadow-[0_-8px_28px_-22px_rgba(19,35,60,0.35)] backdrop-blur-xl lg:hidden"
      style={{ paddingBottom: 'max(env(safe-area-inset-bottom), 6px)' }}
    >
      {visibleItems.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.to === '/dashboard'}
          className={({ isActive }) =>
            cn(
              'flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-xl px-1 text-[10px] font-semibold transition-colors',
              isActive ? 'text-primary' : 'text-text-subtle hover:text-text'
            )
          }
          aria-label={item.label}
        >
          {({ isActive }) => (
            <>
              <span
                className={cn(
                  'flex h-7 w-10 items-center justify-center rounded-lg transition-colors',
                  isActive && 'bg-primary-soft'
                )}
              >
                <ion-icon
                  name={item.icon}
                  class={cn('text-[19px]', isActive ? 'text-primary' : 'text-text-muted')}
                  aria-hidden="true"
                />
              </span>
              <span className="max-w-full truncate">{item.label}</span>
            </>
          )}
        </NavLink>
      ))}

      <button
        type="button"
        onClick={onOpenMore}
        className={cn(
          'flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-xl px-1 text-[10px] font-semibold transition-colors',
          moreActive ? 'text-primary' : 'text-text-subtle hover:text-text'
        )}
        aria-label="Open all navigation"
        aria-current={moreActive ? 'page' : undefined}
      >
        <span
          className={cn(
            'flex h-7 w-10 items-center justify-center rounded-lg transition-colors',
            moreActive && 'bg-primary-soft'
          )}
        >
          <ion-icon
            name="grid-outline"
            class={cn('text-[19px]', moreActive ? 'text-primary' : 'text-text-muted')}
            aria-hidden="true"
          />
        </span>
        <span>More</span>
      </button>
    </nav>
  );
}
