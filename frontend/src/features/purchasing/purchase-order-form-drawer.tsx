import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { purchaseOrderApi, supplierApi, warehouseApi } from '@/api/services';
import type { ProductLookupResult, PurchaseOrder, PurchaseOrderItem } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, formatMoneyString } from '@/utils/format';
import { ProductPicker } from './product-picker';
import {
  emptyToNull,
  extractApiErrors,
  normalizeDiscountType,
  previewLine,
  toNumber,
  type ApiDiscountType,
} from './shared';

interface PurchaseOrderFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: PurchaseOrder | null;
}

interface OrderLineItem {
  key: string;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  product_name: string;
  product_sku: string;
  unit_code: string;
  quantity: string;
  unit_price: string;
  discount_type: ApiDiscountType;
  discount: string;
  tax_rate: string;
}

interface OrderFormValues {
  supplier_id: number | '';
  warehouse_id: number | '';
  order_date: string;
  expected_date: string;
  payment_terms: string;
  notes: string;
  discount_total: string;
  shipping_cost: string;
  other_charges: string;
  items: OrderLineItem[];
}

const NEW = 'new';
const CLOSED = 'closed';

const CURRENCY = 'IDR';

const discountTypeOptions = [
  { label: 'Fixed amount', value: 'amount' },
  { label: 'Percentage', value: 'percent' },
];

let lineKeySeed = 0;

function nextLineKey(): string {
  lineKeySeed += 1;
  return `po-line-${lineKeySeed}`;
}

/**
 * The API resource returns the persisted `discount` column and the `subtotal`
 * it derives; the shared catalog type spells those differently. Reading through
 * this local view keeps an edit honest with what the server actually stored.
 */
type ApiOrderItem = {
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity: string;
  unit_price: string;
  discount_type: string | null;
  discount: string | null;
  tax_rate: string | null;
  product?: { id: number; name: string; sku: string } | null;
  unit?: { id: number; code: string } | null;
};

function apiOrderItem(item: PurchaseOrderItem): ApiOrderItem {
  return item as unknown as ApiOrderItem;
}

function emptyForm(): OrderFormValues {
  return {
    supplier_id: '',
    warehouse_id: '',
    order_date: new Date().toISOString().slice(0, 10),
    expected_date: '',
    payment_terms: '',
    notes: '',
    discount_total: '',
    shipping_cost: '',
    other_charges: '',
    items: [],
  };
}

function lineFromApi(item: PurchaseOrderItem): OrderLineItem {
  const api = apiOrderItem(item);

  return {
    key: nextLineKey(),
    product_id: api.product_id,
    product_variant_id: api.product_variant_id,
    unit_id: api.unit_id,
    product_name: api.product?.name ?? `Product #${api.product_id}`,
    product_sku: api.product?.sku ?? '',
    unit_code: api.unit?.code ?? '',
    quantity: decimalInputValue(api.quantity),
    unit_price: decimalInputValue(api.unit_price),
    discount_type: normalizeDiscountType(api.discount_type),
    discount: decimalInputValue(api.discount),
    tax_rate: decimalInputValue(api.tax_rate),
  };
}

export function PurchaseOrderFormDrawer({
  open,
  onClose,
  initial,
}: PurchaseOrderFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const scopeCompanyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const companyId = isEditing ? initial.company_id : scopeCompanyId;

  const { data: supplierOptionsData } = useQuery({
    queryKey: ['suppliers', 'po-drawer-options', companyId],
    queryFn: () => supplierApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const supplierOptions = (supplierOptionsData?.data ?? []).map((supplier) => ({
    label: supplier.name,
    value: supplier.id,
  }));

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'po-drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [form, setForm] = useState<OrderFormValues>(() => emptyForm());
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);

    if (initial) {
      setForm({
        supplier_id: initial.supplier_id,
        warehouse_id: initial.warehouse_id,
        order_date: initial.order_date ?? new Date().toISOString().slice(0, 10),
        expected_date: initial.expected_date ?? '',
        payment_terms:
          initial.payment_terms === null || initial.payment_terms === undefined
            ? ''
            : String(initial.payment_terms),
        notes: initial.notes ?? '',
        discount_total: decimalInputValue(initial.discount_total),
        shipping_cost: decimalInputValue(initial.shipping_cost),
        other_charges: decimalInputValue(initial.other_charges),
        items: (initial.items ?? []).map(lineFromApi),
      });
    } else {
      setForm(emptyForm());
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: OrderFormValues) => {
      const payload = {
        company_id: companyId,
        branch_id: null,
        warehouse_id: values.warehouse_id,
        supplier_id: values.supplier_id,
        order_date: values.order_date,
        expected_date: emptyToNull(values.expected_date),
        payment_terms: values.payment_terms ? Number(values.payment_terms) : 0,
        currency: CURRENCY,
        notes: emptyToNull(values.notes),
        // Header money is accepted but never trusted: the stored columns are
        // recomputed from the lines by PurchaseCalculationService.
        discount_total: values.discount_total || '0',
        shipping_cost: values.shipping_cost || '0',
        other_charges: values.other_charges || '0',
        // Only the line inputs are sent; every line total is derived server-side.
        items: values.items.map((item) => ({
          product_id: item.product_id,
          product_variant_id: item.product_variant_id,
          unit_id: item.unit_id,
          quantity: item.quantity,
          unit_price: item.unit_price,
          discount: item.discount || '0',
          discount_type: item.discount_type,
          tax_rate: item.tax_rate || '0',
        })),
      };

      if (isEditing) {
        return purchaseOrderApi.update(id, payload);
      }

      return purchaseOrderApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Purchase order updated' : 'Purchase order created',
        variant: 'success',
      });
      invalidate(listQueryKeys.purchaseOrders);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing
          ? 'Failed to update purchase order'
          : 'Failed to create purchase order',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof OrderFormValues>(
    field: K,
    value: OrderFormValues[K]
  ) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setFormErrors((prev) => {
      if (!prev[field]) {
        return prev;
      }
      return { ...prev, [field]: '' };
    });
  };

  const addLine = (product: ProductLookupResult) => {
    setForm((prev) => ({
      ...prev,
      items: [
        ...prev.items,
        {
          key: nextLineKey(),
          product_id: product.id,
          product_variant_id: product.product_variant_id,
          unit_id: product.unit_id,
          product_name: product.name,
          product_sku: product.sku,
          unit_code: product.unit_code ?? '',
          // A new line opens at the product's default cost so the buyer has a
          // starting point; the grand total is still recomputed by the server.
          quantity: '1',
          unit_price: decimalInputValue(product.cost_price),
          discount_type: 'amount',
          discount: '',
          tax_rate: '',
        },
      ],
    }));
  };

  const updateLine = <K extends keyof OrderLineItem>(
    key: string,
    field: K,
    value: OrderLineItem[K]
  ) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((item) =>
        item.key === key ? { ...item, [field]: value } : item
      ),
    }));
  };

  const removeLine = (key: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.filter((item) => item.key !== key),
    }));
  };

  // Display-only preview (spec §46): the server recomputes every figure, so
  // these numbers are a convenience for the user, never the source of truth.
  const preview = form.items.reduce(
    (acc, item) => {
      const line = previewLine(
        toNumber(item.quantity),
        toNumber(item.unit_price),
        item.discount_type,
        toNumber(item.discount),
        toNumber(item.tax_rate)
      );

      acc.subtotal += line.gross;
      acc.itemDiscount += line.discount;
      acc.tax += line.tax;

      return acc;
    },
    { subtotal: 0, itemDiscount: 0, tax: 0 }
  );

  const headerDiscount = toNumber(form.discount_total);
  const shipping = toNumber(form.shipping_cost);
  const otherCharges = toNumber(form.other_charges);
  const grandTotal =
    preview.subtotal -
    preview.itemDiscount -
    headerDiscount +
    preview.tax +
    shipping +
    otherCharges;

  const hasItems = form.items.length > 0;
  const submitDisabled = mutation.isPending || !hasItems;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? `Edit ${initial?.number}` : 'New purchase order'}
      description={
        isEditing
          ? 'Only a draft order can be edited. Totals are recalculated by the server.'
          : 'Raise a draft purchase order for a supplier. Totals are recalculated by the server.'
      }
      width="max-w-4xl"
      footer={
        <>
          <Button
            variant="secondary"
            size="sm"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="purchase-order-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
            disabled={submitDisabled}
          >
            {isEditing ? 'Save changes' : 'Create draft order'}
          </Button>
        </>
      }
    >
      <form
        id="purchase-order-form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate(form);
        }}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Order details">
          <Select
            label="Supplier"
            name="supplier_id"
            options={supplierOptions}
            placeholder="Select supplier"
            value={form.supplier_id}
            onChange={(event) =>
              setField(
                'supplier_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.supplier_id}
            required
          />
          <Select
            label="Warehouse"
            name="warehouse_id"
            options={warehouseOptions}
            placeholder="Select warehouse"
            value={form.warehouse_id}
            onChange={(event) =>
              setField(
                'warehouse_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.warehouse_id}
            required
          />
          <Input
            label="Order date"
            name="order_date"
            type="date"
            value={form.order_date}
            onChange={(event) => setField('order_date', event.target.value)}
            error={formErrors.order_date}
            required
          />
          <Input
            label="Expected date"
            name="expected_date"
            type="date"
            value={form.expected_date}
            onChange={(event) => setField('expected_date', event.target.value)}
            error={formErrors.expected_date}
            hint="Optional"
          />
          <Input
            label="Payment terms"
            name="payment_terms"
            type="number"
            min={0}
            max={365}
            value={form.payment_terms}
            onChange={(event) => setField('payment_terms', event.target.value)}
            error={formErrors.payment_terms}
            hint="Days to pay"
          />
          <Textarea
            label="Notes"
            name="notes"
            value={form.notes}
            onChange={(event) => setField('notes', event.target.value)}
            error={formErrors.notes}
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>

        <FieldGroup title="Items">
          <div className="flex flex-col gap-3 sm:col-span-2 lg:col-span-3">
            <ProductPicker
              onSelect={addLine}
              disabled={mutation.isPending}
              placeholder="Search products to add a line..."
            />

            {hasItems ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full min-w-[860px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt text-xs font-semibold text-text-muted">
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Product
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Qty
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Unit price
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Disc.
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Disc. value
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Tax %
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Line total
                      </th>
                      <th scope="col" className="px-2 py-1.5" />
                    </tr>
                  </thead>
                  <tbody>
                    {form.items.map((item) => {
                      const line = previewLine(
                        toNumber(item.quantity),
                        toNumber(item.unit_price),
                        item.discount_type,
                        toNumber(item.discount),
                        toNumber(item.tax_rate)
                      );

                      return (
                        <tr
                          key={item.key}
                          className="border-b border-border last:border-0"
                        >
                          <td className="px-2 py-1.5">
                            <div className="flex flex-col">
                              <span className="font-medium text-text">
                                {item.product_name}
                              </span>
                              <span className="font-mono text-xs text-text-subtle">
                                {item.product_sku}
                                {item.unit_code ? ` · ${item.unit_code}` : ''}
                              </span>
                            </div>
                          </td>
                          <td className="px-2 py-1.5">
                            <Input
                              name={`quantity-${item.key}`}
                              type="text"
                              inputMode="decimal"
                              value={item.quantity}
                              onChange={(event) =>
                                updateLine(item.key, 'quantity', event.target.value)
                              }
                              className="h-8 w-20 text-right"
                              aria-label="Quantity"
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <Input
                              name={`unit_price-${item.key}`}
                              type="text"
                              inputMode="decimal"
                              value={item.unit_price}
                              onChange={(event) =>
                                updateLine(item.key, 'unit_price', event.target.value)
                              }
                              className="h-8 w-28 text-right"
                              aria-label="Unit price"
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <Select
                              name={`discount_type-${item.key}`}
                              options={discountTypeOptions}
                              value={item.discount_type}
                              onChange={(event) =>
                                updateLine(
                                  item.key,
                                  'discount_type',
                                  normalizeDiscountType(event.target.value)
                                )
                              }
                              className="h-8 w-32"
                              aria-label="Discount type"
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <Input
                              name={`discount-${item.key}`}
                              type="text"
                              inputMode="decimal"
                              value={item.discount}
                              onChange={(event) =>
                                updateLine(item.key, 'discount', event.target.value)
                              }
                              className="h-8 w-24 text-right"
                              aria-label="Discount value"
                            />
                          </td>
                          <td className="px-2 py-1.5">
                            <Input
                              name={`tax_rate-${item.key}`}
                              type="text"
                              inputMode="decimal"
                              value={item.tax_rate}
                              onChange={(event) =>
                                updateLine(item.key, 'tax_rate', event.target.value)
                              }
                              className="h-8 w-20 text-right"
                              aria-label="Tax rate percent"
                            />
                          </td>
                          <td className="px-2 py-1.5 text-right">
                            <span className="whitespace-nowrap font-medium text-text">
                              {formatMoneyString(line.total, CURRENCY)}
                            </span>
                          </td>
                          <td className="px-2 py-1.5 text-right">
                            <button
                              type="button"
                              aria-label="Remove line"
                              title="Remove line"
                              onClick={() => removeLine(item.key)}
                              disabled={mutation.isPending}
                              className="flex h-8 w-8 items-center justify-center rounded-md text-text-muted hover:bg-surface-alt hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
                            >
                              <ion-icon
                                name="trash-outline"
                                class="text-lg"
                                aria-hidden="true"
                              />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="rounded-md border border-dashed border-border bg-surface-alt px-4 py-6 text-center">
                <p className="text-sm font-medium text-text">No items yet</p>
                <p className="mt-1 text-xs text-text-muted">
                  Search for a product above to add the first order line.
                </p>
              </div>
            )}

            {formErrors.items && (
              <span className="flex items-center gap-1 text-xs text-danger">
                <ion-icon name="alert-circle-outline" aria-hidden="true" />
                {formErrors.items}
              </span>
            )}
          </div>
        </FieldGroup>

        <FieldGroup title="Charges &amp; totals">
          <Input
            label="Header discount"
            name="discount_total"
            type="text"
            inputMode="decimal"
            value={form.discount_total}
            onChange={(event) => setField('discount_total', event.target.value)}
            error={formErrors.discount_total}
            hint="Flat amount applied after line discounts"
          />
          <Input
            label="Shipping cost"
            name="shipping_cost"
            type="text"
            inputMode="decimal"
            value={form.shipping_cost}
            onChange={(event) => setField('shipping_cost', event.target.value)}
            error={formErrors.shipping_cost}
          />
          <Input
            label="Other charges"
            name="other_charges"
            type="text"
            inputMode="decimal"
            value={form.other_charges}
            onChange={(event) => setField('other_charges', event.target.value)}
            error={formErrors.other_charges}
          />

          {/* §46: every figure here is a client-side preview only. The backend
              recomputes the stored columns, so this panel is a convenience and
              is never sent as authoritative. */}
          <div className="sm:col-span-2 lg:col-span-3">
            <div className="rounded-md border border-dashed border-border bg-surface-alt p-3">
              <p className="mb-2 flex items-center gap-1 text-xs text-text-subtle">
                <ion-icon name="information-circle-outline" aria-hidden="true" />
                Live preview — the server recalculates every total on save.
              </p>

              <dl className="flex flex-col gap-1.5 text-sm">
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Subtotal</dt>
                  <dd className="tabular-nums text-text">
                    {formatMoneyString(preview.subtotal, CURRENCY)}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Line discounts</dt>
                  <dd className="tabular-nums text-text">
                    -{formatMoneyString(preview.itemDiscount, CURRENCY)}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Header discount</dt>
                  <dd className="tabular-nums text-text">
                    -{formatMoneyString(headerDiscount, CURRENCY)}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Tax</dt>
                  <dd className="tabular-nums text-text">
                    {formatMoneyString(preview.tax, CURRENCY)}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Shipping</dt>
                  <dd className="tabular-nums text-text">
                    {formatMoneyString(shipping, CURRENCY)}
                  </dd>
                </div>
                <div className="flex items-center justify-between">
                  <dt className="text-text-muted">Other charges</dt>
                  <dd className="tabular-nums text-text">
                    {formatMoneyString(otherCharges, CURRENCY)}
                  </dd>
                </div>
                <div className="mt-1 flex items-center justify-between border-t border-border pt-1.5">
                  <dt className="font-semibold text-text">Grand total</dt>
                  <dd className="font-semibold tabular-nums text-text">
                    {formatMoneyString(grandTotal, CURRENCY)}
                  </dd>
                </div>
              </dl>
            </div>
          </div>
        </FieldGroup>
      </form>
    </Drawer>
  );
}
