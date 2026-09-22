import { QueryClient } from '@tanstack/react-query';

/**
 * Global React Query defaults. Mutations keep their data after settling so
 * forms can read the result while the drawer closes, and stale list data is
 * shown instantly while a refetch runs in the background.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      retry: 1,
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: 0,
    },
  },
});

export const listQueryKeys = {
  companies: ['companies'] as const,
  branches: ['branches'] as const,
  warehouses: ['warehouses'] as const,
  registers: ['registers'] as const,
  users: ['users'] as const,
  roles: ['roles'] as const,
  permissions: ['permissions'] as const,
  auditLogs: ['audit-logs'] as const,
  settings: ['settings'] as const,
  dashboard: ['dashboard'] as const,

  // Phase 2 catalog master data.
  categories: ['categories'] as const,
  brands: ['brands'] as const,
  units: ['units'] as const,
  unitConversions: ['unit-conversions'] as const,
  taxes: ['taxes'] as const,
  attributes: ['attributes'] as const,
  attributeValues: ['attribute-values'] as const,

  // Phase 2 products and pricing.
  products: ['products'] as const,
  productVariants: ['product-variants'] as const,
  productBarcodes: ['product-barcodes'] as const,
  priceLists: ['price-lists'] as const,
  productPrices: ['product-prices'] as const,

  // Phase 2 parties.
  customerGroups: ['customer-groups'] as const,
  customers: ['customers'] as const,
  suppliers: ['suppliers'] as const,
  warehouseLocations: ['warehouse-locations'] as const,

  // Phase 2 stock operations.
  stockAdjustments: ['stock-adjustments'] as const,
  stockOpnames: ['stock-opnames'] as const,
  warehouseTransfers: ['warehouse-transfers'] as const,
  stockMovements: ['stock-movements'] as const,

  // Phase 2 purchasing.
  purchaseRequests: ['purchase-requests'] as const,
  purchaseOrders: ['purchase-orders'] as const,
  goodsReceipts: ['goods-receipts'] as const,
  purchaseReturns: ['purchase-returns'] as const,

  // Phase 2 reports.
  reports: ['reports'] as const,

  // Phase 3 sales and invoices.
  sales: ['sales'] as const,

  // Phase 3.3 payments: the admin list and the till's copy of it.
  paymentMethods: ['payment-methods'] as const,

  // Phase 3.4 cash register: shifts, and the drawers they can be opened on.
  registerSessions: ['register-sessions'] as const,

  // Phase 3.5 returns and refunds.
  saleReturns: ['returns'] as const,
  refunds: ['refunds'] as const,
};
