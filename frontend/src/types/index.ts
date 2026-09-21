/**
 * Shared API contract types. These mirror the Laravel API Resources so the
 * frontend and backend agree on response shapes.
 *
 * Every endpoint answers with the envelope { success, message, data, meta }.
 * On a listing the rows sit in `data` as a plain array and the page cursor
 * sits in `meta`, so a list response is `ApiResponse<T[]>` — there is no
 * extra nesting level to unwrap.
 */

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
  meta?: Record<string, unknown>;
  errors?: Record<string, string[]>;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/* ----------------------------- Auth ----------------------------- */

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  avatar: string | null;
  status: UserStatus;
  email_verified_at: string | null;
  last_login_at: string | null;
  created_at: string | null;
  companies?: Company[];
  branches?: Branch[];
  warehouses?: Warehouse[];
  registers?: Register[];
  roles?: Role[];
  permissions?: string[];
}

export type UserStatus = 'active' | 'suspended' | 'inactive';

export interface LoginResponse {
  token: string;
  user: User;
}

/* ------------------------- Business entities ------------------------- */

export interface Company {
  id: number;
  name: string;
  legal_name: string | null;
  code: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  province: string | null;
  country: string;
  postal_code: string | null;
  tax_number: string | null;
  logo: string | null;
  currency: string;
  timezone: string;
  fiscal_year_start: string | null;
  status: string;
  created_at: string | null;
  branches_count?: number;
  warehouses_count?: number;
  registers_count?: number;
}

export type BranchType = 'head_office' | 'outlet' | 'warehouse' | 'other';

export interface Branch {
  id: number;
  company_id: number;
  code: string;
  name: string;
  type: BranchType | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  province: string | null;
  country: string;
  postal_code: string | null;
  timezone: string;
  status: string;
  created_at: string | null;
  company?: Company;
  warehouses_count?: number;
  registers_count?: number;
}

export type WarehouseType = 'main' | 'outlet' | 'production' | 'transit';

export interface Warehouse {
  id: number;
  company_id: number;
  branch_id: number | null;
  code: string;
  name: string;
  description: string | null;
  type: WarehouseType | null;
  status: string;
  created_at: string | null;
  company?: Company;
  branch?: Branch | null;
}

export interface Register {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number | null;
  code: string;
  name: string;
  description: string | null;
  status: string;
  created_at: string | null;
  company?: Company;
  branch?: Branch | null;
  warehouse?: Warehouse | null;
}

export interface Role {
  id: number;
  company_id: number | null;
  name: string;
  display_name: string;
  description: string | null;
  is_system: boolean;
  created_at: string | null;
  permissions?: string[];
  users_count?: number;
}

export interface Permission {
  id: number;
  name: string;
  display_name: string;
  group: string;
}

export interface AuditLog {
  id: number;
  company_id: number | null;
  user_id: number | null;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
}

export interface SettingsResponse {
  values: Record<string, unknown>;
  groups: string[];
}

export interface DashboardWidget {
  key: string;
  label: string;
  value: number;
  currency?: string;
  available: boolean;
}

export interface DashboardData {
  context: {
    company: Pick<Company, 'id' | 'name' | 'code' | 'currency'> | null;
    branch: unknown;
    warehouse: unknown;
    register: unknown;
  };
  widgets: DashboardWidget[];
  charts: Record<
    string,
    { available: boolean; data: Array<Record<string, unknown>> }
  >;
  counts: {
    companies: number;
    branches: number;
    warehouses: number;
    registers: number;
    users: number;
  };
}

/* ----------------------------- Query params ----------------------------- */

export interface ListParams {
  page?: number;
  per_page?: number;
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  company_id?: number;
  branch_id?: number;
  warehouse_id?: number;
  location_id?: number;
  status?: string;
  type?: string;
  product_id?: number;
  product_variant_id?: number;
  category_id?: number;
  brand_id?: number;
  supplier_id?: number;
  customer_id?: number;
  register_id?: number;
  movement_type?: string;
  reference_type?: string;
  reference_id?: number;
  from_warehouse_id?: number;
  to_warehouse_id?: number;
  date_from?: string;
  date_to?: string;
  start_date?: string;
  end_date?: string;
  action?: string;
  entity_type?: string;
  user_id?: number;
  /** Payment methods (3.3): filter by channel, or hide the retired ones. */
  channel?: string;
  active_only?: string;
}

/* ------------------------- Phase 2: Catalog master ------------------------- */

export interface Category {
  id: number;
  company_id: number;
  parent_id: number | null;
  code: string;
  name: string;
  description: string | null;
  image: string | null;
  level: number;
  sort_order: number;
  status: string;
  created_at: string | null;
  parent?: Category | null;
  children?: Category[];
  children_count?: number;
  products_count?: number;
}

export interface Brand {
  id: number;
  company_id: number;
  code: string;
  name: string;
  description: string | null;
  logo: string | null;
  status: string;
  created_at: string | null;
  products_count?: number;
}

export type UnitType = 'quantity' | 'length' | 'weight' | 'volume' | 'area';

export interface Unit {
  id: number;
  company_id: number;
  code: string;
  name: string;
  description: string | null;
  unit_type: UnitType | null;
  is_base: boolean;
  base_unit_id: number | null;
  base_factor: string | null;
  status: string;
  created_at: string | null;
  products_count?: number;
}

export interface UnitConversion {
  id: number;
  company_id: number;
  from_unit_id: number;
  to_unit_id: number;
  factor: string;
  is_active: boolean;
  created_at: string | null;
  from_unit?: Unit;
  to_unit?: Unit;
}

export type TaxType = 'inclusive' | 'exclusive';

export interface Tax {
  id: number;
  company_id: number;
  code: string;
  name: string;
  rate: string;
  type: TaxType | null;
  is_default: boolean;
  is_active: boolean;
  created_at: string | null;
  products_count?: number;
}

export interface Attribute {
  id: number;
  company_id: number;
  code: string;
  name: string;
  data_type: string;
  is_required: boolean;
  is_filterable: boolean;
  created_at: string | null;
  values?: AttributeValue[];
}

export interface AttributeValue {
  id: number;
  company_id: number;
  attribute_id: number;
  value: string;
  label: string | null;
  color: string | null;
  created_at: string | null;
  attribute?: Attribute;
}

/* ------------------------- Phase 2: Products ------------------------- */

export type ProductType =
  | 'simple'
  | 'variable'
  | 'service'
  | 'bundle'
  | 'raw_material'
  | 'finished_good'
  | 'consumable';

export type StockStatus = 'in_stock' | 'low_stock' | 'out_of_stock' | 'not_tracked';

export interface ProductStock {
  on_hand: string;
  reserved: string;
  available: string;
  status: StockStatus;
}

export interface ProductCosting {
  last_purchase_cost: string;
  average_cost: string;
  current_selling_price: string;
  estimated_margin: string;
  estimated_margin_percent: string;
}

export interface Product {
  id: number;
  company_id: number;
  category_id: number | null;
  brand_id: number | null;
  default_unit_id: number | null;
  tax_id: number | null;
  sku: string;
  barcode: string | null;
  name: string;
  description: string | null;
  image: string | null;
  product_type: ProductType | null;
  track_inventory: boolean;
  allow_negative_stock: boolean;
  is_sellable: boolean;
  is_purchasable: boolean;
  is_active: boolean;
  cost_price: string;
  selling_price: string;
  minimum_selling_price: string | null;
  dimensions: {
    weight: string | null;
    length: string | null;
    width: string | null;
    height: string | null;
  };
  minimum_stock: string;
  maximum_stock: string | null;
  reorder_point: string;
  reorder_quantity: string | null;
  stock: ProductStock;
  costing: ProductCosting;
  category?: { id: number; name: string } | null;
  brand?: { id: number; name: string } | null;
  default_unit?: { id: number; name: string; code: string } | null;
  created_at: string | null;
}

export interface ProductVariant {
  id: number;
  company_id: number;
  product_id: number;
  sku: string;
  barcode: string | null;
  name: string;
  cost_price: string;
  selling_price: string;
  is_active: boolean;
  created_at: string | null;
  product?: Pick<Product, 'id' | 'name' | 'sku'>;
  attribute_values?: AttributeValue[];
}

export type BarcodeType = 'ean' | 'upc' | 'code128' | 'qr' | 'internal';

export interface ProductBarcode {
  id: number;
  company_id: number;
  product_id: number;
  product_variant_id: number | null;
  type: BarcodeType | null;
  value: string;
  is_primary: boolean;
  created_at: string | null;
  product?: Pick<Product, 'id' | 'name' | 'sku'>;
}

export type PriceType = 'retail' | 'wholesale' | 'member' | 'reseller';

export interface PriceList {
  id: number;
  company_id: number;
  code: string;
  name: string;
  description: string | null;
  price_type: PriceType | null;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string | null;
  prices_count?: number;
}

export interface ProductPrice {
  id: number;
  company_id: number;
  price_list_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  price: string;
  min_price: string | null;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  created_at: string | null;
  product?: Pick<Product, 'id' | 'name' | 'sku'>;
  price_list?: Pick<PriceList, 'id' | 'name' | 'code'>;
}

export interface ProductLookupResult {
  id: number;
  sku: string;
  barcode: string | null;
  name: string;
  product_type: ProductType | null;
  product_variant_id: number | null;
  variant_sku: string | null;
  unit_id: number | null;
  unit_code: string | null;
  cost_price: string;
  selling_price: string;
  stock: ProductStock;
}

/* ------------------------- Phase 2: Parties ------------------------- */

export type CustomerType = 'individual' | 'company';

export interface CustomerGroup {
  id: number;
  company_id: number;
  code: string;
  name: string;
  description: string | null;
  discount_percent: string | null;
  is_active: boolean;
  created_at: string | null;
  customers_count?: number;
}

export interface Customer {
  id: number;
  company_id: number;
  customer_group_id: number | null;
  price_list_id: number | null;
  customer_code: string;
  name: string;
  type: CustomerType | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  province: string | null;
  country: string;
  postal_code: string | null;
  tax_number: string | null;
  credit_limit: string | null;
  payment_terms: string | null;
  birthday: string | null;
  notes: string | null;
  is_active: boolean;
  created_at: string | null;
  customer_group?: CustomerGroup | null;
  /** Nested by the list/show endpoints so a picker can name the tier. */
  price_list?: { id: number; company_id: number; name: string; status?: string } | null;
}

export interface Supplier {
  id: number;
  company_id: number;
  supplier_code: string;
  name: string;
  company_name: string | null;
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  province: string | null;
  country: string;
  postal_code: string | null;
  tax_number: string | null;
  payment_terms: string | null;
  credit_limit: string | null;
  bank_name: string | null;
  bank_account: string | null;
  bank_account_name: string | null;
  notes: string | null;
  status: string;
  created_at: string | null;
}

export type LocationType = 'zone' | 'rack' | 'bin' | 'area';

export interface WarehouseLocation {
  id: number;
  company_id: number;
  warehouse_id: number;
  parent_id: number | null;
  code: string;
  name: string;
  location_type: LocationType | null;
  aisle: string | null;
  rack: string | null;
  level: string | null;
  is_active: boolean;
  created_at: string | null;
  parent?: WarehouseLocation | null;
  children?: WarehouseLocation[];
}

/* ------------------------- Phase 2: Inventory ------------------------- */

export interface StockBalance {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  location_id: number | null;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  on_hand: string;
  reserved: string;
  incoming: string;
  outgoing: string;
  average_cost: string;
  last_cost: string;
  last_movement_at: string | null;
  product?: Product;
  warehouse?: Warehouse;
  unit?: Unit;
}

export type MovementType =
  | 'opening'
  | 'purchase'
  | 'purchase_return'
  | 'sale'
  | 'sale_return'
  | 'transfer_in'
  | 'transfer_out'
  | 'adjustment_in'
  | 'adjustment_out'
  | 'production_in'
  | 'production_out'
  | 'consumption'
  | 'stock_opname';

export interface StockMovement {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  location_id: number | null;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  created_by: number | null;
  movement_type: MovementType | null;
  reference_type: string | null;
  reference_id: number | null;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  balance_after: string;
  occurred_at: string | null;
  notes: string | null;
  product?: Product;
  warehouse?: Warehouse;
  unit?: Unit;
  creator?: Pick<User, 'id' | 'name'> | null;
}

export type AdjustmentType = 'increase' | 'decrease';
export type AdjustmentReason =
  | 'damage'
  | 'lost'
  | 'found'
  | 'expired'
  | 'counting_error'
  | 'other';
export type StockAdjustmentStatus = 'draft' | 'submitted' | 'approved' | 'posted';

export interface StockAdjustmentItem {
  id: number;
  stock_adjustment_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  adjustment_type: AdjustmentType | null;
  reason: AdjustmentReason | null;
  quantity: string;
  unit_cost: string;
  total_value: string;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface StockAdjustment {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  number: string;
  adjustment_date: string | null;
  status: StockAdjustmentStatus | null;
  adjustment_type: AdjustmentType | null;
  reason: AdjustmentReason | null;
  total_value: string;
  submitted_at: string | null;
  approved_at: string | null;
  approved_by: number | null;
  posted_at: string | null;
  notes: string | null;
  created_at: string | null;
  warehouse?: Warehouse;
  items?: StockAdjustmentItem[];
  items_count?: number;
}

export type StockOpnameStatus =
  | 'draft'
  | 'counting'
  | 'review'
  | 'approved'
  | 'posted';

export interface StockOpnameItem {
  id: number;
  stock_opname_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  system_quantity: string;
  counted_quantity: string | null;
  variance: string | null;
  unit_cost: string;
  variance_value: string | null;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface StockOpname {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  location_id: number | null;
  number: string;
  opname_date: string | null;
  status: StockOpnameStatus | null;
  counted_by: number | null;
  reviewed_by: number | null;
  approved_by: number | null;
  counted_at: string | null;
  posted_at: string | null;
  notes: string | null;
  created_at: string | null;
  warehouse?: Warehouse;
  items?: StockOpnameItem[];
  items_count?: number;
}

export type TransferStatus =
  | 'draft'
  | 'submitted'
  | 'approved'
  | 'shipped'
  | 'received'
  | 'completed'
  | 'cancelled';

export interface WarehouseTransferItem {
  id: number;
  warehouse_transfer_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity: string;
  quantity_received: string;
  unit_cost: string;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface WarehouseTransfer {
  id: number;
  company_id: number;
  number: string;
  transfer_date: string | null;
  from_warehouse_id: number;
  to_warehouse_id: number;
  from_location_id: number | null;
  to_location_id: number | null;
  status: TransferStatus | null;
  requested_by: number | null;
  approved_by: number | null;
  shipped_at: string | null;
  received_at: string | null;
  notes: string | null;
  created_at: string | null;
  from_warehouse?: Warehouse;
  to_warehouse?: Warehouse;
  items?: WarehouseTransferItem[];
  items_count?: number;
}

/* ------------------------- Phase 2: Purchasing ------------------------- */

export type PurchaseRequestStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'converted';

export interface PurchaseRequestItem {
  id: number;
  purchase_request_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity: string;
  unit_price: string;
  total_price: string;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface PurchaseRequest {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  number: string;
  request_date: string | null;
  required_date: string | null;
  status: PurchaseRequestStatus | null;
  total_value: string;
  notes: string | null;
  created_by: number | null;
  submitted_at: string | null;
  approved_at: string | null;
  approved_by: number | null;
  converted_purchase_order_id: number | null;
  created_at: string | null;
  warehouse?: Warehouse;
  items?: PurchaseRequestItem[];
  items_count?: number;
}

export type PurchaseOrderStatus =
  | 'draft'
  | 'submitted'
  | 'approved'
  | 'sent'
  | 'partially_received'
  | 'received'
  | 'closed'
  | 'cancelled';

export type DiscountType = 'amount' | 'percent';

export interface PurchaseOrderItem {
  id: number;
  purchase_order_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  tax_id: number | null;
  description: string | null;
  quantity: string;
  quantity_received: string;
  unit_price: string;
  discount: string;
  discount_type: DiscountType | null;
  tax_rate: string;
  net_price: string;
  tax_amount: string;
  subtotal: string;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface PurchaseOrder {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  supplier_id: number;
  purchase_request_id: number | null;
  approved_by: number | null;
  number: string;
  order_date: string | null;
  expected_date: string | null;
  payment_terms: string | null;
  currency: string;
  status: PurchaseOrderStatus | null;
  subtotal: string;
  item_discount_total: string;
  discount_total: string;
  tax_total: string;
  shipping_cost: string;
  other_charges: string;
  grand_total: string;
  approved_at: string | null;
  sent_at: string | null;
  closed_at: string | null;
  notes: string | null;
  created_at: string | null;
  supplier?: Supplier;
  warehouse?: Warehouse;
  items?: PurchaseOrderItem[];
  items_count?: number;
}

export type GoodsReceiptStatus = 'draft' | 'posted';

export interface GoodsReceiptItem {
  id: number;
  goods_receipt_id: number;
  purchase_order_item_id: number | null;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity_ordered: string;
  quantity_received: string;
  unit_price: string;
  unit_cost: string;
  total_cost: string;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface GoodsReceipt {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  supplier_id: number;
  purchase_order_id: number | null;
  received_by: number | null;
  number: string;
  receipt_date: string | null;
  status: GoodsReceiptStatus | null;
  posted_at: string | null;
  notes: string | null;
  created_at: string | null;
  supplier?: Supplier;
  warehouse?: Warehouse;
  purchase_order?: { id: number; number: string; status: PurchaseOrderStatus | null } | null;
  items?: GoodsReceiptItem[];
  items_count?: number;
}

export type PurchaseReturnStatus = 'draft' | 'posted';

export interface PurchaseReturnItem {
  id: number;
  purchase_return_id: number;
  goods_receipt_item_id: number | null;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity: string;
  unit_cost: string;
  total_amount: string;
  reason: string | null;
  notes: string | null;
  product?: Product;
  unit?: Unit;
}

export interface PurchaseReturn {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number;
  supplier_id: number;
  goods_receipt_id: number | null;
  purchase_order_id: number | null;
  returned_by: number | null;
  number: string;
  return_date: string | null;
  status: PurchaseReturnStatus | null;
  total_amount: string;
  posted_at: string | null;
  notes: string | null;
  created_at: string | null;
  supplier?: Supplier;
  warehouse?: Warehouse;
  goods_receipt?: { id: number; number: string } | null;
  items?: PurchaseReturnItem[];
  items_count?: number;
}

/* ------------------------- Phase 2: Reports ------------------------- */

export interface ReportRow {
  [key: string]: unknown;
}

export interface StockSummaryRow {
  product_id: number;
  product_sku: string;
  product_name: string;
  category_id: number | null;
  brand_id: number | null;
  warehouse_id: number;
  warehouse_code: string;
  warehouse_name: string;
  unit_id: number;
  unit_code: string;
  on_hand: string;
  reserved: string;
  available: string;
  average_cost: string;
  last_cost: string;
  on_hand_value: string;
}

export interface StockCardRow {
  occurred_at: string;
  reference_type: string | null;
  reference_id: number | null;
  reference_label: string;
  movement_type: string | null;
  in_quantity: string;
  out_quantity: string;
  balance: string;
  unit_cost: string;
  total_cost: string;
  notes: string | null;
}

export interface StockValuationRow {
  product_id: number;
  product_sku: string;
  product_name: string;
  warehouse_id: number;
  warehouse_code: string;
  warehouse_name: string;
  unit_code: string;
  on_hand: string;
  average_cost: string;
  last_cost: string;
  on_hand_value: string;
}

export interface StockValuationResponse {
  rows: StockValuationRow[];
  subtotals: Array<Record<string, unknown>>;
  total: string;
}

export interface LowStockRow {
  product_id: number;
  product_sku: string;
  product_name: string;
  warehouse_id: number;
  warehouse_code: string;
  warehouse_name: string;
  unit_code: string;
  on_hand: string;
  reserved: string;
  available: string;
  reorder_point: string;
  minimum_stock: string;
  suggested_reorder_quantity: string;
}

export interface ProductAnalyticsRow {
  product_id: number;
  product_sku: string;
  product_name: string;
  total_stock: string;
  current_cost: string;
  current_price: string;
  estimated_margin: string;
  estimated_margin_percent: string;
  last_purchase_date: string | null;
  last_purchase_quantity: string | null;
  last_supplier_id: number | null;
  last_supplier_name: string | null;
  movement_count: number;
  last_movement_at: string | null;
  days_since_last_movement: number | null;
}

export interface ImportPreviewResponse {
  entity: string;
  summary: { total: number; valid: number; invalid: number };
  errors: Record<string, Record<string, string[]>>;
  file_errors: string[];
  rows: Array<{
    row_number: number;
    values: Record<string, unknown>;
    valid: boolean;
    errors: Record<string, string[]>;
  }>;
}

export interface ImportCommitResponse {
  entity: string;
  imported: number;
}

/* ------------------------- Phase 3.1: Point of sale ------------------------- */

/**
 * A cart is a draft sale on the till. It is not a document: it never reserves
 * stock and never produces revenue, so nothing here feeds inventory or
 * accounting. Checkout in Subphase 3.2 consumes it.
 *
 * Money and quantities arrive as exact decimal strings, the same contract the
 * rest of the API keeps — a JS number would put a float on the money path.
 */
export type CartStatus = 'active' | 'held';

// DiscountType ('amount' | 'percent') is already declared with the purchasing
// documents; a cart line and a cart header use the same two modes.

export type PriceSource =
  | 'price_list'
  | 'customer_group'
  | 'branch'
  | 'default_list'
  | 'variant'
  | 'product';

export interface PosCartItem {
  id: number;
  pos_cart_id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  tax_id: number | null;

  product_name: string;
  product_sku: string;
  barcode: string | null;
  variant_name: string | null;
  unit_code: string | null;

  quantity: string;
  unit_price: string;
  price_source: PriceSource;
  discount: string;
  discount_type: DiscountType;
  discount_amount: string;
  tax_rate: string;
  tax_mode: 'exclusive' | 'inclusive';
  tax_amount: string;
  line_subtotal: string;
  line_total: string;
  notes: string | null;
}

export interface PosCartCustomer {
  id: number;
  customer_code: string;
  name: string;
  phone: string | null;
  price_list_id: number | null;
  customer_group_id: number | null;
  credit_limit: string | null;
  price_list?: { id: number; name: string; status?: string } | null;
}

export interface PosCart {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number | null;
  register_id: number | null;
  user_id: number;
  customer_id: number | null;
  /** Recall code; only present while the cart is parked. */
  number: string | null;
  status: CartStatus;
  label: string | null;
  held_at: string | null;
  currency: string;
  notes: string | null;

  subtotal: string;
  item_discount_total: string;
  /** The cashier's raw discount entry, kept separate from what it resolves to. */
  discount_input: string;
  discount_type: DiscountType;
  discount_total: string;
  tax_total: string;
  /** Portion of tax_total already inside the quoted prices. */
  tax_included_total: string;
  other_charges: string;
  rounding: string;
  grand_total: string;

  item_count?: number;
  total_quantity?: string;
  items: PosCartItem[];
  customer?: PosCartCustomer | null;
  register?: { id: number; code: string; name: string } | null;
  cashier?: { id: number; name: string } | null;
  created_at?: string | null;
  updated_at?: string | null;
}

/** One product as the till grid renders it: price for this customer, stock here. */
export interface PosProduct {
  product_id: number;
  name: string;
  sku: string;
  barcode: string | null;
  product_type: ProductType | null;
  image: string | null;
  track_inventory: boolean;
  allow_negative_stock: boolean;
  unit: { id: number; name: string; code: string } | null;
  category: { id: number; name: string } | null;
  brand: { id: number; name: string } | null;
  price: string;
  price_source: PriceSource;
  price_list_id: number | null;
  catalogue_price: string;
  stock: { on_hand: string; available: string; tracked: boolean; low: boolean };
  variants: Array<{
    id: number;
    sku: string;
    barcode: string | null;
    name: string | null;
    selling_price: string;
    stock: { on_hand: string; available: string };
  }>;
  minimum_selling_price: string | null;
}

/** What a scan resolved to, plus the payload to post straight back to the cart. */
export interface PosScanResult {
  matched_code: string;
  variant_id: number | null;
  product: PosProduct;
  add_to_cart: { product_id: number; product_variant_id?: number; quantity: string };
}

/** A parked cart in the recall queue. */
export interface HeldCart {
  id: number;
  number: string | null;
  label: string | null;
  status: CartStatus;
  held_at: string | null;
  item_count: number;
  total_quantity: string;
  grand_total: string;
  currency: string;
  customer: { id: number; name: string; phone: string | null; customer_code: string } | null;
  cashier: { id: number; name: string } | null;
}

export interface AddCartLinePayload {
  product_id?: number;
  product_variant_id?: number | null;
  barcode?: string;
  quantity?: string;
  notes?: string | null;
}

/** The cart plus the line that was just written, as the add/update endpoints answer. */
export interface PosCartLineResponse {
  item: PosCartItem | null;
  cart: PosCart;
}

/* ------------------------- Phase 3.2: Sales & invoices ------------------------- */

/**
 * Where a sale has got to along Cart → Sales Order → Transaction → Payment →
 * Completed. A ticket is only settled once, so `completed` and `cancelled` are
 * the two states nothing else can follow.
 */
export type SaleStatus =
  | 'draft'
  | 'pending_payment'
  | 'partially_paid'
  | 'paid'
  | 'completed'
  | 'cancelled';

/**
 * What kind of money a tender was — the catalogue, not the shop's configuration.
 *
 * Nine values, closed, because behaviour follows them: only `cash` can hand change
 * back, only `customer_credit` draws down an account. What a shop gets to choose is
 * the `PaymentMethod` row below, which points at exactly one of these.
 */
export type PaymentChannel =
  | 'cash'
  | 'bank_transfer'
  | 'debit'
  | 'credit_card'
  | 'qris'
  | 'e_wallet'
  | 'virtual_account'
  | 'customer_credit'
  | 'other';

/**
 * A tender's own state.
 *
 * `cancelled` is what a withdrawn sale leaves behind, `refunded` and
 * `partially_refunded` what money given back leaves behind; a failed tender still
 * exists as a row, it just settled nothing.
 */
export type SalePaymentStatus =
  | 'pending'
  | 'paid'
  | 'failed'
  | 'cancelled'
  | 'refunded'
  | 'partially_refunded';

export type ReceiptWidth = '58' | '80' | 'a4';

/**
 * One way this shop takes money, as its owner configured it.
 *
 * `takes_tender` and `uses_customer_account` are reported rather than stored: they
 * follow from `channel`, and a till that read them from a checkbox could be talked
 * into opening a cash box for a card payment.
 */
export interface PaymentMethod {
  id: number | null;
  company_id: number | null;
  code: string;
  name: string;
  channel: PaymentChannel;
  channel_label: string;
  /** Gateway key; null records the tender at the counter itself. */
  provider: string | null;
  icon: string | null;
  description: string | null;
  requires_reference: boolean;
  is_default: boolean;
  is_active: boolean;
  sort_order: number;
  settings: Record<string, unknown> | null;
  takes_tender: boolean;
  uses_customer_account: boolean;
  /** Absent unless the endpoint counted it. */
  payments_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface SalePaymentInput {
  /** A configured row wins; `channel` is the fallback for a till with none loaded. */
  payment_method_id?: number | null;
  channel?: PaymentChannel;
  amount: string;
  /** Cash only: what the customer handed over, so the server can compute change. */
  tendered?: string;
  reference?: string | null;
  notes?: string | null;
}

export interface SalePayment {
  id: number;
  sale_id: number;
  number: string;

  /** The shop's configuration this was taken on, once the row behind it. */
  payment_method_id: number | null;
  channel: PaymentChannel;
  channel_label: string;
  /** What the method was called when the money came in — a snapshot, not a join. */
  method_name: string;

  amount: string;
  currency: string;
  tendered: string;
  change: string;
  refunded_amount: string;
  /** amount − refunded_amount: what the shop is still holding. */
  net_amount: string;

  status: SalePaymentStatus;
  status_label: string;
  /** A gateway authorisation id, or the reference the cashier typed. */
  reference: string | null;
  paid_at: string | null;
  metadata: Record<string, unknown> | null;
  notes: string | null;
  received_by: number | null;
  created_at: string | null;
}

/**
 * One invoice line — all snapshot, no joins.
 *
 * The name, SKU, price, tax and unit are the sale's own columns, so a receipt
 * printed next year still reads as it did at the counter even after the product
 * has been renamed, repriced or deleted.
 */
export interface SaleItem {
  id: number;
  sale_id: number;
  product_id: number | null;
  product_variant_id: number | null;
  unit_id: number | null;
  tax_id: number | null;

  product_name: string;
  product_sku: string;
  barcode: string | null;
  variant_name: string | null;
  unit_code: string | null;

  quantity: string;
  unit_price: string;
  price_source: PriceSource;
  discount: string;
  discount_type: DiscountType;
  discount_amount: string;
  tax_rate: string;
  tax_mode: 'exclusive' | 'inclusive';
  tax_amount: string;
  line_subtotal: string;
  line_total: string;
  notes: string | null;
}

/** A sale is also the invoice: the header carries the whole document detail. */
export interface Sale {
  id: number;
  company_id: number;
  branch_id: number | null;
  warehouse_id: number | null;
  register_id: number | null;
  customer_id: number | null;
  cashier_id: number;
  pos_cart_id: number | null;

  number: string;
  date: string;
  status: SaleStatus;
  status_label: string;
  stock_posted_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;

  currency: string;
  subtotal: string;
  item_discount_total: string;
  discount_input: string;
  discount_type: DiscountType;
  discount_total: string;
  tax_total: string;
  tax_included_total: string;
  other_charges: string;
  rounding: string;
  grand_total: string;

  paid_total: string;
  balance_due: string;
  change_due: string;
  /** Unpaid | Partially paid | Paid | Cancelled, derived server-side. */
  payment_status: string;
  fully_paid: boolean;

  notes: string | null;

  outlet: { name: string | null; address: string | null; phone: string | null };
  customer: {
    id: number | null;
    name: string | null;
    code: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
  };

  item_count?: number;
  total_quantity?: string;
  /** Absent on list rows: the list counts lines without loading them. */
  items?: SaleItem[];
  payments: SalePayment[];

  company?: { id: number; name: string; code: string; legal_name: string | null; currency: string } | null;
  branch?: { id: number; name: string; code: string | null; address: string | null } | null;
  warehouse?: { id: number; code: string; name: string } | null;
  register?: { id: number; code: string; name: string } | null;
  cashier?: { id: number | null; name: string | null } | null;

  created_at?: string | null;
  updated_at?: string | null;
}

export interface CheckoutPayload {
  cart_id: number;
  date?: string;
  notes?: string | null;
  payments: SalePaymentInput[];
}

/** The printable document: the server's own HTML for one paper width. */
export interface SaleReceipt {
  width: ReceiptWidth;
  sale: Sale;
  html: string;
}

