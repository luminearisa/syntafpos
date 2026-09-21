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
  { label: 'POS Sales', icon: 'calculator-outline', to: '/pos' },
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

  useEffect(() => {
    onMobileClose();
  }, [location.pathname, onMobileClose]);

  return (
    <>
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
          onClick={onMobileClose}
          aria-hidden="true"
        />
      )}

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex flex-col border-r border-border bg-surface',
          'transition-[width,transform] duration-200 ease-in-out',
          collapsed ? 'w-[60px]' : 'w-60',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
        )}
        aria-label="Main navigation"
      >
        <Brand collapsed={collapsed} />

        <nav className="flex-1 overflow-y-auto overflow-x-hidden py-3">
          {groups.map((group) => {
            const visible = group.items.filter(
              (item) => !item.permission || can(item.permission)
            );

            if (visible.length === 0) {
              return null;
            }

            return (
              <div key={group.title} className="mb-4">
                {!collapsed && (
                  <p className="px-4 pb-1.5 text-[10px] font-semibold tracking-wider text-text-subtle uppercase">
                    {group.title}
                  </p>
                )}

                <ul className="flex flex-col gap-0.5">
                  {visible.map((item) => (
                    <li key={item.to}>
                      <SidebarLink item={item} collapsed={collapsed} />
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}

          {!collapsed && (
            <p className="px-4 pb-1.5 pt-2 text-[10px] font-semibold tracking-wider text-text-subtle uppercase">
              Upcoming
            </p>
          )}
          <ul className="flex flex-col gap-0.5">
            {COMING_SOON.map((item) => (
              <li key={item.to}>
                <span
                  title={`${item.label} — available in a later phase`}
                  className={cn(
                    'flex items-center gap-2.5 px-4 py-1.5 text-sm text-text-subtle',
                    'cursor-not-allowed'
                  )}
                >
                  <ion-icon name={item.icon} class="text-lg shrink-0" aria-hidden="true" />
                  {!collapsed && <span className="truncate">{item.label}</span>}
                </span>
              </li>
            ))}
          </ul>
        </nav>

        <CollapseFooter collapsed={collapsed} />
      </aside>
    </>
  );
}

function Brand({ collapsed }: { collapsed: boolean }) {
  return (
    <div className="flex h-14 items-center gap-2.5 border-b border-border px-4">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary text-white">
        <ion-icon name="pricetags-outline" class="text-lg" aria-hidden="true" />
      </span>

      {!collapsed && (
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-text">Ultimate POS</p>
          <p className="truncate text-[10px] text-text-subtle">Business Management</p>
        </div>
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
          'flex items-center gap-2.5 px-4 py-1.5 text-sm transition-colors',
          'focus-visible:outline-2 focus-visible:outline-primary',
          isActive
            ? 'bg-primary-soft font-medium text-primary'
            : 'text-text-muted hover:bg-surface-alt hover:text-text'
        )
      }
    >
      <ion-icon name={item.icon} class="text-lg shrink-0" aria-hidden="true" />
      {label}
    </NavLink>
  );
}

function CollapseFooter({ collapsed }: { collapsed: boolean }) {
  return (
    <div className="hidden border-t border-border p-3 lg:block">
      <p
        className={cn(
          'text-[10px] text-text-subtle',
          collapsed ? 'text-center' : 'px-1'
        )}
      >
        {collapsed ? 'v1.0' : 'Ultimate POS — Phase 1'}
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
