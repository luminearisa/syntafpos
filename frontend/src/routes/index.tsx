import { type ReactNode, Suspense, lazy } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import type { RouteObject } from 'react-router-dom';
import { useAuthStore } from '@/stores/auth-store';
import { AppLayout } from '@/layouts/app-layout';
import { LoadingState } from '@/components/ui/state';

const LoginPage = lazy(() => import('@/features/auth/login-page'));
const ForgotPasswordPage = lazy(
  () => import('@/features/auth/forgot-password-page')
);
const ResetPasswordPage = lazy(() => import('@/features/auth/reset-password-page'));
const DashboardPage = lazy(() => import('@/features/dashboard/dashboard-page'));
const CompaniesPage = lazy(() => import('@/features/companies/companies-page'));
const BranchesPage = lazy(() => import('@/features/branches/branches-page'));
const WarehousesPage = lazy(() => import('@/features/warehouses/warehouses-page'));
const RegistersPage = lazy(() => import('@/features/registers/registers-page'));
const UsersPage = lazy(() => import('@/features/users/users-page'));
const RolesPage = lazy(() => import('@/features/roles/roles-page'));
const AuditLogsPage = lazy(() => import('@/features/audit/audit-logs-page'));
const SettingsPage = lazy(() => import('@/features/settings/settings-page'));
const ProfilePage = lazy(() => import('@/features/auth/profile-page'));
const ChangePasswordPage = lazy(
  () => import('@/features/auth/change-password-page')
);
const NotFoundPage = lazy(() => import('@/pages/not-found-page'));

const ProductsPage = lazy(() => import('@/features/products/products-page'));
const CategoriesPage = lazy(() => import('@/features/catalog/categories-page'));
const BrandsPage = lazy(() => import('@/features/catalog/brands-page'));
const UnitsPage = lazy(() => import('@/features/catalog/units-page'));
const TaxesPage = lazy(() => import('@/features/catalog/taxes-page'));
const PaymentMethodsPage = lazy(
  () => import('@/features/payments/payment-methods-page')
);
const AttributesPage = lazy(() => import('@/features/catalog/attributes-page'));
const CustomersPage = lazy(() => import('@/features/parties/customers-page'));
const SuppliersPage = lazy(() => import('@/features/parties/suppliers-page'));
const InventoryOverviewPage = lazy(
  () => import('@/features/inventory/inventory-overview-page')
);
const StockMovementsPage = lazy(
  () => import('@/features/inventory/stock-movements-page')
);
const LowStockPage = lazy(() => import('@/features/inventory/low-stock-page'));
const StockOpnamesPage = lazy(() => import('@/features/inventory/stock-opnames-page'));
const StockAdjustmentsPage = lazy(
  () => import('@/features/inventory/stock-adjustments-page')
);
const WarehouseTransfersPage = lazy(
  () => import('@/features/inventory/warehouse-transfers-page')
);
const PurchaseRequestsPage = lazy(
  () => import('@/features/purchasing/purchase-requests-page')
);
const PurchaseOrdersPage = lazy(() => import('@/features/purchasing/purchase-orders-page'));
const GoodsReceiptsPage = lazy(() => import('@/features/purchasing/goods-receipts-page'));
const PurchaseReturnsPage = lazy(
  () => import('@/features/purchasing/purchase-returns-page')
);
const InventoryReportsPage = lazy(() => import('@/features/reports/inventory-reports-page'));
const PurchasingReportsPage = lazy(
  () => import('@/features/reports/purchasing-reports-page')
);
const ProductAnalyticsPage = lazy(() => import('@/features/reports/product-analytics-page'));
const PosTillPage = lazy(() => import('@/features/pos/pos-till-page'));
const SalesPage = lazy(() => import('@/features/sales/sales-page'));
const SaleDetailPage = lazy(() => import('@/features/sales/sale-detail-page'));

function PageWrapper({ children }: { children: ReactNode }) {
  return (
    <Suspense fallback={<LoadingState className="py-20" label="Loading page..." />}>
      {children}
    </Suspense>
  );
}

function RequireAuth(): ReactNode {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const location = useLocation();

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  }

  return <AppLayout><Outlet /></AppLayout>;
}

function RedirectWhenAuthenticated(): ReactNode {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  return <Outlet />;
}

export const routes: RouteObject[] = [
  {
    element: <RedirectWhenAuthenticated />,
    children: [
      {
        path: '/login',
        element: (
          <PageWrapper>
            <LoginPage />
          </PageWrapper>
        ),
      },
      {
        path: '/forgot-password',
        element: (
          <PageWrapper>
            <ForgotPasswordPage />
          </PageWrapper>
        ),
      },
      {
        path: '/reset-password',
        element: (
          <PageWrapper>
            <ResetPasswordPage />
          </PageWrapper>
        ),
      },
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      { index: true, element: <Navigate to="/dashboard" replace /> },
      {
        path: '/dashboard',
        element: (
          <PageWrapper>
            <DashboardPage />
          </PageWrapper>
        ),
      },
      {
        path: '/companies',
        element: (
          <PageWrapper>
            <CompaniesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/branches',
        element: (
          <PageWrapper>
            <BranchesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/warehouses',
        element: (
          <PageWrapper>
            <WarehousesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/registers',
        element: (
          <PageWrapper>
            <RegistersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/users',
        element: (
          <PageWrapper>
            <UsersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/roles',
        element: (
          <PageWrapper>
            <RolesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/audit-logs',
        element: (
          <PageWrapper>
            <AuditLogsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/products',
        element: (
          <PageWrapper>
            <ProductsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/categories',
        element: (
          <PageWrapper>
            <CategoriesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/brands',
        element: (
          <PageWrapper>
            <BrandsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/units',
        element: (
          <PageWrapper>
            <UnitsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/taxes',
        element: (
          <PageWrapper>
            <TaxesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/attributes',
        element: (
          <PageWrapper>
            <AttributesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/payment-methods',
        element: (
          <PageWrapper>
            <PaymentMethodsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/customers',
        element: (
          <PageWrapper>
            <CustomersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/suppliers',
        element: (
          <PageWrapper>
            <SuppliersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/overview',
        element: (
          <PageWrapper>
            <InventoryOverviewPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/movements',
        element: (
          <PageWrapper>
            <StockMovementsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/low-stock',
        element: (
          <PageWrapper>
            <LowStockPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/stock-opnames',
        element: (
          <PageWrapper>
            <StockOpnamesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/adjustments',
        element: (
          <PageWrapper>
            <StockAdjustmentsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/inventory/transfers',
        element: (
          <PageWrapper>
            <WarehouseTransfersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/purchasing/requests',
        element: (
          <PageWrapper>
            <PurchaseRequestsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/purchasing/orders',
        element: (
          <PageWrapper>
            <PurchaseOrdersPage />
          </PageWrapper>
        ),
      },
      {
        path: '/purchasing/receipts',
        element: (
          <PageWrapper>
            <GoodsReceiptsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/purchasing/returns',
        element: (
          <PageWrapper>
            <PurchaseReturnsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/pos',
        element: (
          <PageWrapper>
            <PosTillPage />
          </PageWrapper>
        ),
      },
      {
        path: '/sales',
        element: (
          <PageWrapper>
            <SalesPage />
          </PageWrapper>
        ),
      },
      {
        path: '/sales/:id',
        element: (
          <PageWrapper>
            <SaleDetailPage />
          </PageWrapper>
        ),
      },
      {
        path: '/reports/inventory',
        element: (
          <PageWrapper>
            <InventoryReportsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/reports/purchasing',
        element: (
          <PageWrapper>
            <PurchasingReportsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/reports/products',
        element: (
          <PageWrapper>
            <ProductAnalyticsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/settings',
        element: (
          <PageWrapper>
            <SettingsPage />
          </PageWrapper>
        ),
      },
      {
        path: '/profile',
        element: (
          <PageWrapper>
            <ProfilePage />
          </PageWrapper>
        ),
      },
      {
        path: '/profile/password',
        element: (
          <PageWrapper>
            <ChangePasswordPage />
          </PageWrapper>
        ),
      },
    ],
  },
  {
    path: '*',
    element: (
      <PageWrapper>
        <NotFoundPage />
      </PageWrapper>
    ),
  },
];

