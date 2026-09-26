import { type Dispatch, type SetStateAction, useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { cn } from '@/utils/format';
import { useAuthStore } from '@/stores/auth-store';

export interface NavItem {
  label: string;
  to: string;
  icon: string;
  permission?: string;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

/**
 * Modules scheduled for later phases. They stay visible as disabled placeholders
 * so the navigation architecture is discoverable without dead links.
 */
const COMING_SOON = [
  { label: 'Accounting', icon: 'book-outline', to: '/accounting' },
] as const;

function buildGroups(): NavGroup[] {
  return [
    {
      title: 'Overview',
      items: [
        { label: 'Dashboard', to: '/dashboard', icon: 'speedometer-outline' },
        {
          label: 'Inventory Overview',
          to: '/inventory/overview',
          icon: 'layers-outline',
          permission: 'inventory.view',
        },
      ],
    },
    {
      title: 'Sales',
      items: [
        {
          label: 'Point of Sale',
          to: '/pos',
          icon: 'calculator-outline',
          permission: 'pos.view',
        },
        {
          label: 'Sales & Invoices',
          to: '/sales',
          icon: 'receipt-outline',
          permission: 'sales.view',
        },
        {
          label: 'Register Shifts',
          to: '/registers/shifts',
          icon: 'cash-outline',
          permission: 'register_sessions.view',
        },
        {
          label: 'Sales Returns',
          to: '/sales/returns',
          icon: 'return-down-back-outline',
          permission: 'sales.view',
        },
        {
          label: 'Refunds',
          to: '/refunds',
          icon: 'refresh-circle-outline',
          permission: 'refunds.view',
        },
      ],
    },
    {
      title: 'Master Data',
      items: [
        {
          label: 'Products',
          to: '/products',
          icon: 'barcode-outline',
          permission: 'products.view',
        },
        {
          label: 'Categories',
          to: '/categories',
          icon: 'git-branch-outline',
          permission: 'categories.view',
        },
        {
          label: 'Brands',
          to: '/brands',
          icon: 'pricetag-outline',
          permission: 'brands.view',
        },
        {
          label: 'Units',
          to: '/units',
          icon: 'scale-outline',
          permission: 'units.view',
        },
        {
          label: 'Taxes',
          to: '/taxes',
          icon: 'pie-chart-outline',
          permission: 'taxes.view',
        },
        {
          label: 'Attributes',
          to: '/attributes',
          icon: 'options-outline',
          permission: 'attributes.view',
        },
        {
          label: 'Payment Methods',
          to: '/payment-methods',
          icon: 'wallet-outline',
          permission: 'payment_methods.view',
        },
      ],
    },
    {
      title: 'Business',
      items: [
        {
          label: 'Companies',
          to: '/companies',
          icon: 'business-outline',
          permission: 'companies.view',
        },
        {
          label: 'Branches',
          to: '/branches',
          icon: 'storefront-outline',
          permission: 'branches.view',
        },
        {
          label: 'Warehouses',
          to: '/warehouses',
          icon: 'cube-outline',
          permission: 'warehouses.view',
        },
        {
          label: 'Registers',
          to: '/registers',
          icon: 'cash-outline',
          permission: 'registers.view',
        },
        {
          label: 'Customers',
          to: '/customers',
          icon: 'people-circle-outline',
          permission: 'customers.view',
        },
        {
          label: 'Suppliers',
          to: '/suppliers',
          icon: 'train-outline',
          permission: 'suppliers.view',
        },
      ],
    },
    {
      title: 'Inventory',
      items: [
        {
          label: 'Stock Movements',
          to: '/inventory/movements',
          icon: 'swap-vertical-outline',
          permission: 'inventory.view',
        },
        {
          label: 'Low Stock',
          to: '/inventory/low-stock',
          icon: 'warning-outline',
          permission: 'inventory.view',
        },
        {
          label: 'Stock Opnames',
          to: '/inventory/stock-opnames',
          icon: 'clipboard-outline',
          permission: 'inventory.opname',
        },
        {
          label: 'Adjustments',
          to: '/inventory/adjustments',
          icon: 'remove-circle-outline',
          permission: 'inventory.adjust',
        },
        {
          label: 'Transfers',
          to: '/inventory/transfers',
          icon: 'git-compare-outline',
          permission: 'inventory.transfer',
        },
      ],
    },
    {
      title: 'Purchasing',
      items: [
        {
          label: 'Purchase Requests',
          to: '/purchasing/requests',
          icon: 'document-text-outline',
          permission: 'purchases.view',
        },
        {
          label: 'Purchase Orders',
          to: '/purchasing/orders',
          icon: 'cart-outline',
          permission: 'purchases.view',
        },
        {
          label: 'Goods Receipts',
          to: '/purchasing/receipts',
          icon: 'download-outline',
          permission: 'purchases.view',
        },
        {
          label: 'Purchase Returns',
          to: '/purchasing/returns',
          icon: 'return-down-back-outline',
          permission: 'purchases.view',
        },
      ],
    },
    {
      title: 'Reports',
      items: [
        {
          label: 'Inventory Reports',
          to: '/reports/inventory',
          icon: 'bar-chart-outline',
          permission: 'reports.inventory',
        },
        {
          label: 'Purchasing Reports',
          to: '/reports/purchasing',
          icon: 'stats-chart-outline',
          permission: 'reports.purchasing',
        },
        {
          label: 'Product Analytics',
          to: '/reports/products',
          icon: 'trending-up-outline',
          permission: 'reports.inventory',
        },
      ],
    },
    {
      title: 'Administration',
      items: [
        {
          label: 'Users',
          to: '/users',
          icon: 'people-outline',
          permission: 'users.view',
        },
        {
          label: 'Roles & Permissions',
          to: '/roles',
          icon: 'key-outline',
          permission: 'roles.view',
        },
        {
          label: 'Audit Logs',
          to: '/audit-logs',
          icon: 'document-text-outline',
          permission: 'audit.view',
        },
        {
          label: 'Settings',
          to: '/settings',
          icon: 'settings-outline',
          permission: 'settings.view',
        },
      ],
    },
  ];
}

interface SidebarProps {
  collapsed: boolean;
  mobileOpen: boolean;
  onMobileClose: () => void;
}

const STORAGE_KEY = 'pos.sidebar.collapsed';

export function Sidebar({ collapsed, mobileOpen, onMobileClose }: SidebarProps) {
  const can = useAuthStore((state) => state.can);
  const groups = buildGroups();
  const location = useLocation();
  const compact = collapsed && !mobileOpen;

  useEffect(() => {
    onMobileClose();
  }, [location.pathname, onMobileClose]);

  return (
    <>
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-[2px] lg:hidden"
          onClick={onMobileClose}
          aria-hidden="true"
        />
      )}

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-[#253651] bg-[#111e33] text-white shadow-2xl',
          'transition-[width,transform] duration-200 ease-in-out',
          compact ? 'w-[72px]' : 'w-64',
          mobileOpen
            ? 'visible translate-x-0'
            : 'invisible -translate-x-full lg:visible lg:translate-x-0'
        )}
        aria-label="Main navigation"
      >
        <Brand collapsed={compact} mobileOpen={mobileOpen} onMobileClose={onMobileClose} />

        <nav className="flex-1 overscroll-contain overflow-y-auto overflow-x-hidden py-4">
          {groups.map((group) => {
            const visible = group.items.filter(
              (item) => !item.permission || can(item.permission)
            );

            if (visible.length === 0) {
              return null;
            }

            return (
              <div key={group.title} className="mb-4">
                {!compact && (
                  <p className="px-5 pb-2 text-[10px] font-bold tracking-[0.16em] text-slate-400 uppercase">
                    {group.title}
                  </p>
                )}

                <ul className="flex flex-col gap-0.5">
                  {visible.map((item) => (
                    <li key={item.to}>
                      <SidebarLink item={item} collapsed={compact} />
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}

          {!compact && (
            <p className="px-5 pb-2 pt-3 text-[10px] font-bold tracking-[0.16em] text-slate-400 uppercase">
              Upcoming
            </p>
          )}
          <ul className="flex flex-col gap-0.5">
            {COMING_SOON.map((item) => (
              <li key={item.to}>
                <span
                  title={`${item.label} — available in a later phase`}
                  className={cn(
                    'mx-2.5 flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] text-slate-400',
                    'cursor-not-allowed'
                  )}
                >
                  <ion-icon name={item.icon} class="text-lg shrink-0" aria-hidden="true" />
                  {!compact && <span className="truncate">{item.label}</span>}
                </span>
              </li>
            ))}
          </ul>
        </nav>

        <CollapseFooter collapsed={compact} />
      </aside>
    </>
  );
}

function Brand({
  collapsed,
  mobileOpen,
  onMobileClose,
}: {
  collapsed: boolean;
  mobileOpen: boolean;
  onMobileClose: () => void;
}) {
  return (
    <div className="flex h-16 shrink-0 items-center gap-3 border-b border-[#253651] px-4">
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#6588ff] to-[#3158c9] text-white shadow-md shadow-blue-950/30">
        <ion-icon name="calculator-outline" class="text-xl" aria-hidden="true" />
      </span>

      {!collapsed && (
        <div className="min-w-0 flex-1">
          <p className="truncate text-[15px] font-bold tracking-[-0.02em] text-white">SyntafPOS</p>
          <p className="truncate text-[10px] font-medium tracking-wide text-slate-400">RETAIL OPERATIONS</p>
        </div>
      )}

      {mobileOpen && (
        <button
          type="button"
          onClick={onMobileClose}
          className="ml-auto flex h-9 w-9 items-center justify-center rounded-lg text-slate-300 transition-colors hover:bg-white/10 hover:text-white lg:hidden"
          aria-label="Close navigation menu"
        >
          <ion-icon name="close-outline" class="text-xl" aria-hidden="true" />
        </button>
      )}
    </div>
  );
}

function SidebarLink({ item, collapsed }: { item: NavItem; collapsed: boolean }) {
  const label = collapsed ? (
    <span className="sr-only">{item.label}</span>
  ) : (
    <span className="truncate">{item.label}</span>
  );

  return (
    <NavLink
      to={item.to}
      title={collapsed ? item.label : undefined}
      className={({ isActive }) =>
        cn(
          'group relative mx-2 flex min-h-10 items-center gap-3 rounded-lg px-3 text-[13px] font-medium transition-colors duration-150',
          collapsed && 'justify-center px-0',
          'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#91a9ff]',
          isActive
            ? 'bg-white/10 text-white shadow-sm'
            : 'text-slate-300 hover:bg-white/[0.06] hover:text-white'
        )
      }
    >
      {({ isActive }) => (
        <>
          <span
            className={cn(
              'absolute inset-y-2 left-0 w-[3px] rounded-r-full',
              isActive ? 'bg-[#86a5ff]' : 'bg-transparent'
            )}
            aria-hidden="true"
          />
          <ion-icon
            name={item.icon}
            class={cn('shrink-0 text-[19px]', isActive ? 'text-[#afc2ff]' : 'text-slate-400 group-hover:text-slate-200')}
            aria-hidden="true"
          />
          {label}
        </>
      )}
    </NavLink>
  );
}

function CollapseFooter({ collapsed }: { collapsed: boolean }) {
  return (
    <div className="hidden border-t border-[#253651] px-4 py-3 lg:block">
      <p
        className={cn(
          'text-[10px] text-slate-400',
          collapsed ? 'text-center' : 'px-1'
        )}
      >
        {collapsed ? 'v1.0' : 'SyntafPOS — Retail workspace'}
      </p>
    </div>
  );
}

export function useSidebarCollapsed(): [boolean, Dispatch<SetStateAction<boolean>>] {
  const [collapsed, setCollapsed] = useState(() => {
    return localStorage.getItem(STORAGE_KEY) === 'true';
  });

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, String(collapsed));
  }, [collapsed]);

  return [collapsed, setCollapsed];
}
