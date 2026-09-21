import { request } from './client';
import type {
  ApiResponse,
  Attribute,
  AttributeValue,
  AuditLog,
  Brand,
  Branch,
  Category,
  Company,
  Customer,
  CustomerGroup,
  DashboardData,
  GoodsReceipt,
  ImportCommitResponse,
  ImportPreviewResponse,
  ListParams,
  LoginResponse,
  LowStockRow,
  AddCartLinePayload,
  HeldCart,
  Permission,
  PosCart,
  PosCartLineResponse,
  PosProduct,
  PosScanResult,
  PriceList,
  Product,
  ProductAnalyticsRow,
  ProductBarcode,
  ProductLookupResult,
  ProductPrice,
  ProductVariant,
  PurchaseOrder,
  PurchaseRequest,
  PurchaseReturn,
  ReceiptWidth,
  Register,
  ReportRow,
  CheckoutPayload,
  Role,
  Sale,
  SalePaymentInput,
  SaleReceipt,
  SettingsResponse,
  StockAdjustment,
  StockCardRow,
  StockMovement,
  StockOpname,
  StockSummaryRow,
  StockValuationResponse,
  Supplier,
  Tax,
  Unit,
  UnitConversion,
  User,
  Warehouse,
  WarehouseLocation,
  WarehouseTransfer,
} from '@/types';

export const authApi = {
  login: (email: string, password: string, deviceName = 'web') =>
    request<LoginResponse>({
      method: 'POST',
      url: '/auth/login',
      data: { email, password, device_name: deviceName },
    }),

  logout: () => request<null>({ method: 'POST', url: '/auth/logout' }),

  me: () => request<{ user: User }>({ method: 'GET', url: '/auth/me' }),

  changePassword: (
    currentPassword: string,
    password: string,
    passwordConfirmation: string
  ) =>
    request<null>({
      method: 'PUT',
      url: '/auth/password',
      data: {
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      },
    }),

  forgotPassword: (email: string) =>
    request<null>({
      method: 'POST',
      url: '/auth/forgot-password',
      data: { email },
    }),

  resetPassword: (
    token: string,
    email: string,
    password: string,
    passwordConfirmation: string
  ) =>
    request<null>({
      method: 'POST',
      url: '/auth/reset-password',
      data: {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      },
    }),
};

export const dashboardApi = {
  index: () =>
    request<DashboardData>({ method: 'GET', url: '/dashboard' }),
};

export const companyApi = {
  list: (params: ListParams = {}) =>
    request<Company[]>({ method: 'GET', url: '/companies', params }),

  show: (id: number) =>
    request<Company>({ method: 'GET', url: `/companies/${id}` }),

  create: (data: Partial<Company>) =>
    request<Company>({ method: 'POST', url: '/companies', data }),

  update: (id: number, data: Partial<Company>) =>
    request<Company>({ method: 'PUT', url: `/companies/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/companies/${id}` }),
};

export const branchApi = {
  list: (params: ListParams = {}) =>
    request<Branch[]>({ method: 'GET', url: '/branches', params }),

  show: (id: number) =>
    request<Branch>({ method: 'GET', url: `/branches/${id}` }),

  create: (data: Partial<Branch>) =>
    request<Branch>({ method: 'POST', url: '/branches', data }),

  update: (id: number, data: Partial<Branch>) =>
    request<Branch>({ method: 'PUT', url: `/branches/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/branches/${id}` }),
};

export const warehouseApi = {
  list: (params: ListParams = {}) =>
    request<Warehouse[]>({
      method: 'GET',
      url: '/warehouses',
      params,
    }),

  show: (id: number) =>
    request<Warehouse>({ method: 'GET', url: `/warehouses/${id}` }),

  create: (data: Partial<Warehouse>) =>
    request<Warehouse>({ method: 'POST', url: '/warehouses', data }),

  update: (id: number, data: Partial<Warehouse>) =>
    request<Warehouse>({ method: 'PUT', url: `/warehouses/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/warehouses/${id}` }),
};

export const registerApi = {
  list: (params: ListParams = {}) =>
    request<Register[]>({
      method: 'GET',
      url: '/registers',
      params,
    }),

  show: (id: number) =>
    request<Register>({ method: 'GET', url: `/registers/${id}` }),

  create: (data: Partial<Register>) =>
    request<Register>({ method: 'POST', url: '/registers', data }),

  update: (id: number, data: Partial<Register>) =>
    request<Register>({ method: 'PUT', url: `/registers/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/registers/${id}` }),
};

export const userApi = {
  list: (params: ListParams = {}) =>
    request<User[]>({ method: 'GET', url: '/users', params }),

  show: (id: number) => request<User>({ method: 'GET', url: `/users/${id}` }),

  create: (data: Record<string, unknown>) =>
    request<User>({ method: 'POST', url: '/users', data }),

  update: (id: number, data: Record<string, unknown>) =>
    request<User>({ method: 'PUT', url: `/users/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/users/${id}` }),
};

export const roleApi = {
  list: (params: ListParams = {}) =>
    request<Role[]>({ method: 'GET', url: '/roles', params }),

  show: (id: number) => request<Role>({ method: 'GET', url: `/roles/${id}` }),

  create: (data: Record<string, unknown>) =>
    request<Role>({ method: 'POST', url: '/roles', data }),

  update: (id: number, data: Record<string, unknown>) =>
    request<Role>({ method: 'PUT', url: `/roles/${id}`, data }),

  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/roles/${id}` }),
};

export const permissionApi = {
  list: (params: { group?: string } = {}) =>
    request<Permission[]>({
      method: 'GET',
      url: '/permissions',
      params,
    }),
};

export const auditApi = {
  list: (params: ListParams = {}) =>
    request<AuditLog[]>({
      method: 'GET',
      url: '/audit-logs',
      params,
    }),
};

export const settingsApi = {
  index: () =>
    request<SettingsResponse>({ method: 'GET', url: '/settings' }),

  show: (key: string) =>
    request<{ key: string; value: unknown }>({
      method: 'GET',
      url: `/settings/${encodeURIComponent(key)}`,
    }),

  update: (values: Record<string, unknown>) =>
    request<SettingsResponse>({ method: 'PUT', url: '/settings', data: { values } }),
};

export type {
  ApiResponse,
  Attribute,
  AttributeValue,
  AuditLog,
  Brand,
  Branch,
  Category,
  Company,
  Customer,
  CustomerGroup,
  DashboardData,
  GoodsReceipt,
  ImportCommitResponse,
  ImportPreviewResponse,
  LoginResponse,
  LowStockRow,
  AddCartLinePayload,
  HeldCart,
  Permission,
  PosCart,
  PosCartLineResponse,
  PosProduct,
  PosScanResult,
  PriceList,
  Product,
  ProductAnalyticsRow,
  ProductBarcode,
  ProductLookupResult,
  ProductPrice,
  ProductVariant,
  PurchaseOrder,
  PurchaseRequest,
  PurchaseReturn,
  Register,
  ReportRow,
  Role,
  SettingsResponse,
  StockAdjustment,
  StockCardRow,
  StockMovement,
  StockOpname,
  StockSummaryRow,
  StockValuationResponse,
  Supplier,
  Tax,
  Unit,
  UnitConversion,
  User,
  Warehouse,
  WarehouseLocation,
  WarehouseTransfer,
};

/* ------------------------- Phase 2: Catalog master ------------------------- */

export const categoryApi = {
  list: (params: ListParams = {}) =>
    request<Category[]>({ method: 'GET', url: '/categories', params }),
  show: (id: number) =>
    request<Category>({ method: 'GET', url: `/categories/${id}` }),
  create: (data: Partial<Category>) =>
    request<Category>({ method: 'POST', url: '/categories', data }),
  update: (id: number, data: Partial<Category>) =>
    request<Category>({ method: 'PUT', url: `/categories/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/categories/${id}` }),
};

export const brandApi = {
  list: (params: ListParams = {}) =>
    request<Brand[]>({ method: 'GET', url: '/brands', params }),
  show: (id: number) =>
    request<Brand>({ method: 'GET', url: `/brands/${id}` }),
  create: (data: Partial<Brand>) =>
    request<Brand>({ method: 'POST', url: '/brands', data }),
  update: (id: number, data: Partial<Brand>) =>
    request<Brand>({ method: 'PUT', url: `/brands/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/brands/${id}` }),
};

export const unitApi = {
  list: (params: ListParams = {}) =>
    request<Unit[]>({ method: 'GET', url: '/units', params }),
  show: (id: number) =>
    request<Unit>({ method: 'GET', url: `/units/${id}` }),
  create: (data: Partial<Unit>) =>
    request<Unit>({ method: 'POST', url: '/units', data }),
  update: (id: number, data: Partial<Unit>) =>
    request<Unit>({ method: 'PUT', url: `/units/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/units/${id}` }),
  convert: (id: number, params: Record<string, unknown>) =>
    request<Record<string, unknown>>({ method: 'GET', url: `/units/${id}/convert`, params }),
};

export const unitConversionApi = {
  list: (params: ListParams = {}) =>
    request<UnitConversion[]>({ method: 'GET', url: '/unit-conversions', params }),
  show: (id: number) =>
    request<UnitConversion>({ method: 'GET', url: `/unit-conversions/${id}` }),
  create: (data: Partial<UnitConversion>) =>
    request<UnitConversion>({ method: 'POST', url: '/unit-conversions', data }),
  update: (id: number, data: Partial<UnitConversion>) =>
    request<UnitConversion>({ method: 'PUT', url: `/unit-conversions/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/unit-conversions/${id}` }),
};

export const taxApi = {
  list: (params: ListParams = {}) =>
    request<Tax[]>({ method: 'GET', url: '/taxes', params }),
  show: (id: number) =>
    request<Tax>({ method: 'GET', url: `/taxes/${id}` }),
  create: (data: Partial<Tax>) =>
    request<Tax>({ method: 'POST', url: '/taxes', data }),
  update: (id: number, data: Partial<Tax>) =>
    request<Tax>({ method: 'PUT', url: `/taxes/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/taxes/${id}` }),
};

export const attributeApi = {
  list: (params: ListParams = {}) =>
    request<Attribute[]>({ method: 'GET', url: '/attributes', params }),
  show: (id: number) =>
    request<Attribute>({ method: 'GET', url: `/attributes/${id}` }),
  create: (data: Partial<Attribute>) =>
    request<Attribute>({ method: 'POST', url: '/attributes', data }),
  update: (id: number, data: Partial<Attribute>) =>
    request<Attribute>({ method: 'PUT', url: `/attributes/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/attributes/${id}` }),
};

export const attributeValueApi = {
  list: (params: ListParams = {}) =>
    request<AttributeValue[]>({ method: 'GET', url: '/attribute-values', params }),
  show: (id: number) =>
    request<AttributeValue>({ method: 'GET', url: `/attribute-values/${id}` }),
  create: (data: Partial<AttributeValue>) =>
    request<AttributeValue>({ method: 'POST', url: '/attribute-values', data }),
  update: (id: number, data: Partial<AttributeValue>) =>
    request<AttributeValue>({ method: 'PUT', url: `/attribute-values/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/attribute-values/${id}` }),
};

/* ------------------------- Phase 2: Products ------------------------- */

export const productApi = {
  list: (params: ListParams = {}) =>
    request<Product[]>({ method: 'GET', url: '/products', params }),
  show: (id: number) =>
    request<Product>({ method: 'GET', url: `/products/${id}` }),
  create: (data: Partial<Product>) =>
    request<Product>({ method: 'POST', url: '/products', data }),
  update: (id: number, data: Partial<Product>) =>
    request<Product>({ method: 'PUT', url: `/products/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/products/${id}` }),
  lookup: (params: Record<string, unknown>) =>
    request<ProductLookupResult[]>({ method: 'GET', url: '/products/lookup', params }),
};

export const productVariantApi = {
  list: (params: ListParams = {}) =>
    request<ProductVariant[]>({ method: 'GET', url: '/product-variants', params }),
  show: (id: number) =>
    request<ProductVariant>({ method: 'GET', url: `/product-variants/${id}` }),
  create: (data: Partial<ProductVariant>) =>
    request<ProductVariant>({ method: 'POST', url: '/product-variants', data }),
  update: (id: number, data: Partial<ProductVariant>) =>
    request<ProductVariant>({ method: 'PUT', url: `/product-variants/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/product-variants/${id}` }),
};

export const productBarcodeApi = {
  list: (params: ListParams = {}) =>
    request<ProductBarcode[]>({ method: 'GET', url: '/product-barcodes', params }),
  show: (id: number) =>
    request<ProductBarcode>({ method: 'GET', url: `/product-barcodes/${id}` }),
  create: (data: Partial<ProductBarcode>) =>
    request<ProductBarcode>({ method: 'POST', url: '/product-barcodes', data }),
  update: (id: number, data: Partial<ProductBarcode>) =>
    request<ProductBarcode>({ method: 'PUT', url: `/product-barcodes/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/product-barcodes/${id}` }),
};

export const priceListApi = {
  list: (params: ListParams = {}) =>
    request<PriceList[]>({ method: 'GET', url: '/price-lists', params }),
  show: (id: number) =>
    request<PriceList>({ method: 'GET', url: `/price-lists/${id}` }),
  create: (data: Partial<PriceList>) =>
    request<PriceList>({ method: 'POST', url: '/price-lists', data }),
  update: (id: number, data: Partial<PriceList>) =>
    request<PriceList>({ method: 'PUT', url: `/price-lists/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/price-lists/${id}` }),
};

export const productPriceApi = {
  list: (params: ListParams = {}) =>
    request<ProductPrice[]>({ method: 'GET', url: '/product-prices', params }),
  show: (id: number) =>
    request<ProductPrice>({ method: 'GET', url: `/product-prices/${id}` }),
  create: (data: Partial<ProductPrice>) =>
    request<ProductPrice>({ method: 'POST', url: '/product-prices', data }),
  update: (id: number, data: Partial<ProductPrice>) =>
    request<ProductPrice>({ method: 'PUT', url: `/product-prices/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/product-prices/${id}` }),
};

/* ------------------------- Phase 2: Parties ------------------------- */

export const customerGroupApi = {
  list: (params: ListParams = {}) =>
    request<CustomerGroup[]>({ method: 'GET', url: '/customer-groups', params }),
  show: (id: number) =>
    request<CustomerGroup>({ method: 'GET', url: `/customer-groups/${id}` }),
  create: (data: Partial<CustomerGroup>) =>
    request<CustomerGroup>({ method: 'POST', url: '/customer-groups', data }),
  update: (id: number, data: Partial<CustomerGroup>) =>
    request<CustomerGroup>({ method: 'PUT', url: `/customer-groups/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/customer-groups/${id}` }),
};

export const customerApi = {
  list: (params: ListParams = {}) =>
    request<Customer[]>({ method: 'GET', url: '/customers', params }),
  show: (id: number) =>
    request<Customer>({ method: 'GET', url: `/customers/${id}` }),
  create: (data: Partial<Customer>) =>
    request<Customer>({ method: 'POST', url: '/customers', data }),
  update: (id: number, data: Partial<Customer>) =>
    request<Customer>({ method: 'PUT', url: `/customers/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/customers/${id}` }),
};

export const supplierApi = {
  list: (params: ListParams = {}) =>
    request<Supplier[]>({ method: 'GET', url: '/suppliers', params }),
  show: (id: number) =>
    request<Supplier>({ method: 'GET', url: `/suppliers/${id}` }),
  create: (data: Partial<Supplier>) =>
    request<Supplier>({ method: 'POST', url: '/suppliers', data }),
  update: (id: number, data: Partial<Supplier>) =>
    request<Supplier>({ method: 'PUT', url: `/suppliers/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/suppliers/${id}` }),
  summary: (id: number) =>
    request<Record<string, unknown>>({ method: 'GET', url: `/suppliers/${id}/summary` }),
};

export const warehouseLocationApi = {
  list: (params: ListParams = {}) =>
    request<WarehouseLocation[]>({ method: 'GET', url: '/warehouse-locations', params }),
  show: (id: number) =>
    request<WarehouseLocation>({ method: 'GET', url: `/warehouse-locations/${id}` }),
  create: (data: Partial<WarehouseLocation>) =>
    request<WarehouseLocation>({ method: 'POST', url: '/warehouse-locations', data }),
  update: (id: number, data: Partial<WarehouseLocation>) =>
    request<WarehouseLocation>({ method: 'PUT', url: `/warehouse-locations/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/warehouse-locations/${id}` }),
};

/* ------------------------- Phase 2: Stock operations ------------------------- */

export const stockAdjustmentApi = {
  list: (params: ListParams = {}) =>
    request<StockAdjustment[]>({ method: 'GET', url: '/stock-adjustments', params }),
  show: (id: number) =>
    request<StockAdjustment>({ method: 'GET', url: `/stock-adjustments/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<StockAdjustment>({ method: 'POST', url: '/stock-adjustments', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<StockAdjustment>({ method: 'PUT', url: `/stock-adjustments/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/stock-adjustments/${id}` }),
  submit: (id: number) =>
    request<StockAdjustment>({ method: 'POST', url: `/stock-adjustments/${id}/submit` }),
  approve: (id: number) =>
    request<StockAdjustment>({ method: 'POST', url: `/stock-adjustments/${id}/approve` }),
  post: (id: number) =>
    request<StockAdjustment>({ method: 'POST', url: `/stock-adjustments/${id}/post` }),
};

export const stockOpnameApi = {
  list: (params: ListParams = {}) =>
    request<StockOpname[]>({ method: 'GET', url: '/stock-opnames', params }),
  show: (id: number) =>
    request<StockOpname>({ method: 'GET', url: `/stock-opnames/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<StockOpname>({ method: 'POST', url: '/stock-opnames', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<StockOpname>({ method: 'PUT', url: `/stock-opnames/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/stock-opnames/${id}` }),
  count: (id: number) =>
    request<StockOpname>({ method: 'POST', url: `/stock-opnames/${id}/count` }),
  review: (id: number) =>
    request<StockOpname>({ method: 'POST', url: `/stock-opnames/${id}/review` }),
  approve: (id: number) =>
    request<StockOpname>({ method: 'POST', url: `/stock-opnames/${id}/approve` }),
  post: (id: number) =>
    request<StockOpname>({ method: 'POST', url: `/stock-opnames/${id}/post` }),
};

export const warehouseTransferApi = {
  list: (params: ListParams = {}) =>
    request<WarehouseTransfer[]>({ method: 'GET', url: '/warehouse-transfers', params }),
  show: (id: number) =>
    request<WarehouseTransfer>({ method: 'GET', url: `/warehouse-transfers/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<WarehouseTransfer>({ method: 'POST', url: '/warehouse-transfers', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<WarehouseTransfer>({ method: 'PUT', url: `/warehouse-transfers/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/warehouse-transfers/${id}` }),
  submit: (id: number) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/submit` }),
  approve: (id: number) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/approve` }),
  ship: (id: number) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/ship` }),
  receive: (id: number, data?: Record<string, unknown>) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/receive`, data: data ?? {} }),
  complete: (id: number) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/complete` }),
  cancel: (id: number) =>
    request<WarehouseTransfer>({ method: 'POST', url: `/warehouse-transfers/${id}/cancel` }),
};

/* ------------------------- Phase 2: Purchasing ------------------------- */

export const purchaseRequestApi = {
  list: (params: ListParams = {}) =>
    request<PurchaseRequest[]>({ method: 'GET', url: '/purchase-requests', params }),
  show: (id: number) =>
    request<PurchaseRequest>({ method: 'GET', url: `/purchase-requests/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<PurchaseRequest>({ method: 'POST', url: '/purchase-requests', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<PurchaseRequest>({ method: 'PUT', url: `/purchase-requests/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/purchase-requests/${id}` }),
  submit: (id: number) =>
    request<PurchaseRequest>({ method: 'POST', url: `/purchase-requests/${id}/submit` }),
  approve: (id: number) =>
    request<PurchaseRequest>({ method: 'POST', url: `/purchase-requests/${id}/approve` }),
  reject: (id: number) =>
    request<PurchaseRequest>({ method: 'POST', url: `/purchase-requests/${id}/reject` }),
  convert: (id: number) =>
    request<PurchaseRequest>({ method: 'POST', url: `/purchase-requests/${id}/convert` }),
};

export const purchaseOrderApi = {
  list: (params: ListParams = {}) =>
    request<PurchaseOrder[]>({ method: 'GET', url: '/purchase-orders', params }),
  show: (id: number) =>
    request<PurchaseOrder>({ method: 'GET', url: `/purchase-orders/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<PurchaseOrder>({ method: 'POST', url: '/purchase-orders', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<PurchaseOrder>({ method: 'PUT', url: `/purchase-orders/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/purchase-orders/${id}` }),
  submit: (id: number) =>
    request<PurchaseOrder>({ method: 'POST', url: `/purchase-orders/${id}/submit` }),
  approve: (id: number) =>
    request<PurchaseOrder>({ method: 'POST', url: `/purchase-orders/${id}/approve` }),
  send: (id: number) =>
    request<PurchaseOrder>({ method: 'POST', url: `/purchase-orders/${id}/send` }),
  close: (id: number) =>
    request<PurchaseOrder>({ method: 'POST', url: `/purchase-orders/${id}/close` }),
  cancel: (id: number) =>
    request<PurchaseOrder>({ method: 'POST', url: `/purchase-orders/${id}/cancel` }),
};

export const goodsReceiptApi = {
  list: (params: ListParams = {}) =>
    request<GoodsReceipt[]>({ method: 'GET', url: '/goods-receipts', params }),
  show: (id: number) =>
    request<GoodsReceipt>({ method: 'GET', url: `/goods-receipts/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<GoodsReceipt>({ method: 'POST', url: '/goods-receipts', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<GoodsReceipt>({ method: 'PUT', url: `/goods-receipts/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/goods-receipts/${id}` }),
  post: (id: number) =>
    request<GoodsReceipt>({ method: 'POST', url: `/goods-receipts/${id}/post` }),
};

export const purchaseReturnApi = {
  list: (params: ListParams = {}) =>
    request<PurchaseReturn[]>({ method: 'GET', url: '/purchase-returns', params }),
  show: (id: number) =>
    request<PurchaseReturn>({ method: 'GET', url: `/purchase-returns/${id}` }),
  create: (data: Record<string, unknown>) =>
    request<PurchaseReturn>({ method: 'POST', url: '/purchase-returns', data }),
  update: (id: number, data: Record<string, unknown>) =>
    request<PurchaseReturn>({ method: 'PUT', url: `/purchase-returns/${id}`, data }),
  remove: (id: number) =>
    request<null>({ method: 'DELETE', url: `/purchase-returns/${id}` }),
  post: (id: number) =>
    request<PurchaseReturn>({ method: 'POST', url: `/purchase-returns/${id}/post` }),
};

/* ------------------------- Phase 2: Reports ------------------------- */

export const inventoryReportApi = {
  stockSummary: (params: ListParams = {}) =>
    request<StockSummaryRow[]>({ method: 'GET', url: '/reports/inventory/stock-summary', params }),
  stockCard: (params: ListParams) =>
    request<StockCardRow[]>({ method: 'GET', url: '/reports/inventory/stock-card', params }),
  stockMovements: (params: ListParams = {}) =>
    request<StockMovement[]>({ method: 'GET', url: '/reports/inventory/stock-movements', params }),
  lowStock: (params: ListParams = {}) =>
    request<LowStockRow[]>({ method: 'GET', url: '/reports/inventory/low-stock', params }),
  stockValuation: (params: ListParams = {}) =>
    request<StockValuationResponse>({ method: 'GET', url: '/reports/inventory/stock-valuation', params }),
  stockOpnames: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/inventory/stock-opnames', params }),
  warehouseTransfers: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/inventory/warehouse-transfers', params }),
};

export const purchasingReportApi = {
  summary: (params: ListParams = {}) =>
    request<ReportRow>({ method: 'GET', url: '/reports/purchasing/summary', params }),
  detail: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/detail', params }),
  bySupplier: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/by-supplier', params }),
  byProduct: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/by-product', params }),
  byBranch: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/by-branch', params }),
  byWarehouse: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/by-warehouse', params }),
  returns: (params: ListParams = {}) =>
    request<ReportRow>({ method: 'GET', url: '/reports/purchasing/returns', params }),
  outstanding: (params: ListParams = {}) =>
    request<ReportRow[]>({ method: 'GET', url: '/reports/purchasing/outstanding', params }),
};

export const supplierReportApi = {
  summary: (params: ListParams = {}) =>
    request<ReportRow>({ method: 'GET', url: '/reports/suppliers/summary', params }),
  detail: (supplierId: number, params: ListParams = {}) =>
    request<ReportRow>({ method: 'GET', url: `/reports/suppliers/${supplierId}/detail`, params }),
};

export const productAnalyticsApi = {
  analytics: (params: ListParams = {}) =>
    request<ProductAnalyticsRow[]>({ method: 'GET', url: '/reports/products/analytics', params }),
};

/* ------------------------- Phase 2: Import & export ------------------------- */

export const importApi = {
  preview: (entity: string, file: File) => {
    const form = new FormData();
    form.append('entity', entity);
    form.append('file', file);

    return request<ImportPreviewResponse>({
      method: 'POST',
      url: '/imports/preview',
      data: form,
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
  commit: (entity: string, file: File) => {
    const form = new FormData();
    form.append('entity', entity);
    form.append('file', file);

    return request<ImportCommitResponse>({
      method: 'POST',
      url: '/imports/commit',
      data: form,
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  },
};

export const exportApi = {
  url: (entity: string, params: ListParams = {}) => {
    const search = new URLSearchParams({ format: 'csv' });
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        search.append(key, String(value));
      }
    });

    return `/api/v1/exports/${entity}?${search.toString()}`;
  },
};

/* ------------------------- Phase 3.1: Point of sale ------------------------- */

/**
 * The till.
 *
 * `current` and `create` answer the same way on purpose: opening the till is
 * idempotent, so a reload, a dropped request or a second tab resumes the cart
 * the cashier is already working instead of orphaning a scan.
 *
 * There is no client-side cart total here. Every mutation returns the cart as
 * the server recomputed it, which is what keeps the number on screen and the
 * number checkout charges from drifting apart.
 */
export const posApi = {
  searchProducts: (params: {
    search?: string;
    barcode?: string;
    category_id?: number;
    brand_id?: number;
    page?: number;
    per_page?: number;
  } = {}) =>
    request<PosProduct[]>({ method: 'GET', url: '/pos/products/search', params }),

  /** A scan is the search endpoint with `barcode`, so it shares one call. */
  scan: (barcode: string) =>
    request<PosScanResult>({
      method: 'GET',
      url: '/pos/products/search',
      params: { barcode },
    }),

  current: () => request<PosCart>({ method: 'GET', url: '/pos/cart' }),
  create: () => request<PosCart>({ method: 'POST', url: '/pos/cart' }),

  show: (cartId: number) => request<PosCart>({ method: 'GET', url: `/pos/cart/${cartId}` }),

  /** Customer, cart discount, note, label. Money entered here is an input only. */
  update: (cartId: number, data: Record<string, unknown>) =>
    request<PosCart>({ method: 'PUT', url: `/pos/cart/${cartId}`, data }),

  addItem: (cartId: number, data: AddCartLinePayload) =>
    request<PosCartLineResponse>({ method: 'POST', url: `/pos/cart/${cartId}/items`, data }),

  updateItem: (
    cartId: number,
    itemId: number,
    data: { quantity?: string; discount?: string; discount_type?: string; notes?: string | null }
  ) =>
    request<PosCartLineResponse>({
      method: 'PUT',
      url: `/pos/cart/${cartId}/items/${itemId}`,
      data,
    }),

  removeItem: (cartId: number, itemId: number) =>
    request<PosCart>({ method: 'DELETE', url: `/pos/cart/${cartId}/items/${itemId}` }),

  clear: (cartId: number) =>
    request<PosCart>({ method: 'DELETE', url: `/pos/cart/${cartId}/items` }),

  hold: (cartId: number) => request<PosCart>({ method: 'POST', url: `/pos/cart/${cartId}/hold` }),

  held: () => request<HeldCart[]>({ method: 'GET', url: '/pos/cart/held' }),

  recall: (number: string) =>
    request<PosCart>({ method: 'POST', url: '/pos/cart/recall', data: { number } }),

  removeHeld: (cartId: number) =>
    request<null>({ method: 'DELETE', url: `/pos/cart/${cartId}` }),
};

/**
 * Sales (Phase 3.2): the documents a till produces.
 *
 * There is no update and no delete, because the backend has none: a posted
 * transaction is corrected by cancelling it, so the receipt, the stock movement
 * and the takings keep telling the same story.
 */
export const saleApi = {
  list: (params: ListParams = {}) =>
    request<Sale[]>({ method: 'GET', url: '/sales', params }),

  show: (id: number) => request<Sale>({ method: 'GET', url: `/sales/${id}` }),

  /** Checkout: the cart becomes a numbered sale, stock leaves, tenders are recorded. */
  checkout: (data: CheckoutPayload) =>
    request<Sale>({ method: 'POST', url: '/sales', data }),

  /** Take what is still owed; the sale posts its stock once the balance clears. */
  complete: (id: number, payments: SalePaymentInput[] = []) =>
    request<Sale>({ method: 'POST', url: `/sales/${id}/complete`, data: { payments } }),

  cancel: (id: number, reason: string | null) =>
    request<Sale>({ method: 'POST', url: `/sales/${id}/cancel`, data: { reason } }),

  /** The printable document, rendered by the server for one paper width. */
  receipt: (id: number, width: ReceiptWidth = '80') =>
    request<SaleReceipt>({
      method: 'GET',
      url: `/sales/${id}/receipt`,
      params: { width },
    }),
};
