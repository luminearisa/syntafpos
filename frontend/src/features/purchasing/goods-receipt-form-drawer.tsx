import { useState, type ChangeEvent, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  goodsReceiptApi,
  purchaseOrderApi,
  supplierApi,
  warehouseApi,
} from '@/api/services';
import type {
  GoodsReceipt,
  GoodsReceiptItem,
  ProductLookupResult,
  PurchaseOrder,
  PurchaseOrderItem,
} from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, formatMoneyString, labelFor } from '@/utils/format';
import { ProductPicker } from './product-picker';
import { apiErrorMessage, emptyToNull, extractApiErrors, toNumber } from './shared';

interface GoodsReceiptFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: GoodsReceipt | null;
}

interface ReceiptLine {
  key: string;
  purchase_order_item_id: number | null;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  product_name: string;
  product_sku: string;
  unit_code: string;
  quantity_ordered: string;
  quantity_received: string;
  unit_cost: string;
}

interface ReceiptFormValues {
  supplier_id: number | '';
  warehouse_id: number | '';
  purchase_order_id: number | '';
  receipt_date: string;
  notes: string;
  items: ReceiptLine[];
}

const NEW = 'new';
const CLOSED = 'closed';

const CURRENCY = 'IDR';

// An order that can still be received against: it has been authorised and has
// not yet been closed or cancelled.
const OPEN_ORDER_STATUSES = new Set([
  'submitted',
  'approved',
  'sent',
  'partially_received',
]);

let lineKeySeed = 0;

function nextLineKey(): string {
  lineKeySeed += 1;
  return `gr-line-${lineKeySeed}`;
}

/**
 * The API resource returns the persisted `discount`/`subtotal` columns under
 * their real names; read the raw payload so seeding reflects the server.
 */
type ApiOrderItem = {
  id: number;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number;
  quantity: string;
  quantity_received: string;
  unit_price: string;
  net_price: string;
  product?: { id: number; name: string; sku: string } | null;
  unit?: { id: number; code: string } | null;
};

function apiOrderItem(item: PurchaseOrderItem): ApiOrderItem {
  return item as unknown as ApiOrderItem;
}

/**
 * Seed a receipt from an order's lines: each line opens at what is still
 * outstanding, priced at the order's net line price.
 */
function seedFromOrder(order: PurchaseOrder): ReceiptLine[] {
  return (order.items ?? []).map((raw) => {
    const item = apiOrderItem(raw);
    const outstanding = Math.max(
      toNumber(item.quantity) - toNumber(item.quantity_received),
      0
    );

    return {
      key: nextLineKey(),
      purchase_order_item_id: item.id,
      product_id: item.product_id,
      product_variant_id: item.product_variant_id,
      unit_id: item.unit_id,
      product_name: item.product?.name ?? `Product #${item.product_id}`,
      product_sku: item.product?.sku ?? '',
      unit_code: item.unit?.code ?? '',
      quantity_ordered: decimalInputValue(item.quantity),
      quantity_received: String(outstanding),
      unit_cost: decimalInputValue(item.net_price),
    };
  });
}

function lineFromApi(item: GoodsReceiptItem): ReceiptLine {
  return {
    key: nextLineKey(),
    purchase_order_item_id: item.purchase_order_item_id,
    product_id: item.product_id,
    product_variant_id: item.product_variant_id,
    unit_id: item.unit_id,
    product_name: item.product?.name ?? `Product #${item.product_id}`,
    product_sku: item.product?.sku ?? '',
    unit_code: item.unit?.code ?? '',
    quantity_ordered: decimalInputValue(item.quantity_ordered),
    quantity_received: decimalInputValue(item.quantity_received),
    unit_cost: decimalInputValue(item.unit_cost || item.unit_price),
  };
}

function emptyForm(): ReceiptFormValues {
  return {
    supplier_id: '',
    warehouse_id: '',
    purchase_order_id: '',
    receipt_date: new Date().toISOString().slice(0, 10),
    notes: '',
    items: [],
  };
}

export function GoodsReceiptFormDrawer({
  open,
  onClose,
  initial,
}: GoodsReceiptFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const scopeCompanyId = useAuthStore((state) => state.scope.companyId);
  const companyId = isEditing ? initial.company_id : scopeCompanyId;
  const { toast } = useToast();
  const invalidate = useInvalidateList();
  const queryClient = useQueryClient();

  const { data: supplierOptionsData } = useQuery({
    queryKey: ['suppliers', 'gr-drawer-options', companyId],
    queryFn: () => supplierApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const supplierOptions = (supplierOptionsData?.data ?? []).map((supplier) => ({
    label: supplier.name,
    value: supplier.id,
  }));

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'gr-drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const { data: openOrdersData } = useQuery({
    queryKey: ['purchase-orders', 'open', companyId],
    queryFn: () =>
      purchaseOrderApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null && !isEditing,
  });
  const openOrderOptions = (openOrdersData?.data ?? [])
    .filter((order) => OPEN_ORDER_STATUSES.has(order.status ?? ''))
    .map((order) => ({
      label: `${order.number} · ${labelFor.documentStatus(order.status)}`,
      value: order.id,
    }));

  const [form, setForm] = useState<ReceiptFormValues>(() => emptyForm());
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
        purchase_order_id: initial.purchase_order_id ?? '',
        receipt_date: initial.receipt_date ?? new Date().toISOString().slice(0, 10),
        notes: initial.notes ?? '',
        items: (initial.items ?? []).map(lineFromApi),
      });
    } else {
      setForm(emptyForm());
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: ReceiptFormValues) => {
      const payload = {
        company_id: companyId,
        branch_id: null,
        warehouse_id: values.warehouse_id,
        supplier_id: values.supplier_id,
        purchase_order_id: values.purchase_order_id || null,
        receipt_date: values.receipt_date,
        notes: emptyToNull(values.notes),
        items: values.items.map((item) => ({
          purchase_order_item_id: item.purchase_order_item_id,
          product_id: item.product_id,
          product_variant_id: item.product_variant_id,
          unit_id: item.unit_id,
          quantity_ordered: item.quantity_ordered || null,
          quantity_received: item.quantity_received || '0',
          unit_price: item.unit_cost || null,
          unit_cost: item.unit_cost || null,
        })),
      };

      if (isEditing) {
        return goodsReceiptApi.update(id, payload);
      }

      return goodsReceiptApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Goods receipt updated' : 'Goods receipt created',
        variant: 'success',
      });
      invalidate(listQueryKeys.goodsReceipts);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing
          ? 'Failed to update goods receipt'
          : 'Failed to create goods receipt',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof ReceiptFormValues>(
    field: K,
    value: ReceiptFormValues[K]
  ) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setFormErrors((prev) => {
      if (!prev[field]) {
        return prev;
      }
      return { ...prev, [field]: '' };
    });
  };

  /**
   * Choosing an order pulls its lines through the show endpoint and seeds the
   * receipt with what is still outstanding, so the dock records real quantities.
   */
  const handlePurchaseOrderChange = async (
    event: ChangeEvent<HTMLSelectElement>
  ) => {
    const poId = event.target.value === '' ? '' : Number(event.target.value);
    setField('purchase_order_id', poId);

    if (poId === '') {
      setForm((prev) => ({ ...prev, items: [] }));
      return;
    }

    try {
      const response = await queryClient.fetchQuery({
        queryKey: ['purchase-order', poId],
        queryFn: () => purchaseOrderApi.show(poId),
      });
      const order = response.data;

      setForm((prev) => ({
        ...prev,
        supplier_id: prev.supplier_id || order.supplier_id,
        warehouse_id: prev.warehouse_id || order.warehouse_id,
        items: seedFromOrder(order),
      }));
    } catch (error) {
      toast({
        title: 'Could not load the purchase order',
        message: apiErrorMessage(error, 'Failed to load purchase order lines'),
        variant: 'error',
      });
    }
  };

  const addLine = (product: ProductLookupResult) => {
    setForm((prev) => ({
      ...prev,
      items: [
        ...prev.items,
        {
          key: nextLineKey(),
          purchase_order_item_id: null,
          product_id: product.id,
          product_variant_id: product.product_variant_id,
          unit_id: product.unit_id,
          product_name: product.name,
          product_sku: product.sku,
          unit_code: product.unit_code ?? '',
          quantity_ordered: '',
          quantity_received: '1',
          unit_cost: decimalInputValue(product.cost_price),
        },
      ],
    }));
  };

  const updateLine = <K extends keyof ReceiptLine>(
    key: string,
    field: K,
    value: ReceiptLine[K]
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

  // Display-only preview (spec §46): the ledger value is computed server-side
  // when the receipt posts, so this is a convenience, never the source of truth.
  const estimatedValue = form.items.reduce(
    (acc, item) => acc + toNumber(item.quantity_received) * toNumber(item.unit_cost),
    0
  );

  const hasItems = form.items.length > 0;
  const linkedOrder = form.purchase_order_id !== '';
  const submitDisabled = mutation.isPending || !hasItems;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? `Edit ${initial?.number}` : 'New goods receipt'}
      description={
        isEditing
          ? 'Only a draft receipt can be edited. Posting it moves stock into the ledger.'
          : 'Record what arrived. Posting it moves stock into the ledger.'
      }
      width="max-w-3xl"
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
            form="goods-receipt-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
            disabled={submitDisabled}
          >
            {isEditing ? 'Save changes' : 'Create draft receipt'}
          </Button>
        </>
      }
    >
      <form
        id="goods-receipt-form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate(form);
        }}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Receipt details">
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
          <div className="flex flex-col gap-1">
            <Select
              label="Purchase order"
              name="purchase_order_id"
              options={openOrderOptions}
              placeholder="Receive ad-hoc (no order)"
              value={form.purchase_order_id}
              onChange={handlePurchaseOrderChange}
              error={formErrors.purchase_order_id}
              disabled={isEditing || mutation.isPending}
            />
            <p className="text-xs text-text-subtle">
              {isEditing
                ? 'The linked order cannot be changed.'
                : 'Selecting an order loads its outstanding lines.'}
            </p>
          </div>
          <Input
            label="Receipt date"
            name="receipt_date"
            type="date"
            value={form.receipt_date}
            onChange={(event) => setField('receipt_date', event.target.value)}
            error={formErrors.receipt_date}
            required
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

        <FieldGroup title="Received items">
          <div className="flex flex-col gap-3 sm:col-span-2 lg:col-span-3">
            {linkedOrder ? (
              <p className="flex items-center gap-1.5 text-xs text-text-subtle">
                <ion-icon name="information-circle-outline" aria-hidden="true" />
                Lines come from the linked purchase order. Clear the order to
                receive ad-hoc items instead.
              </p>
            ) : (
              <ProductPicker
                onSelect={addLine}
                disabled={mutation.isPending}
                placeholder="Search products to add a line..."
              />
            )}

            {hasItems ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full min-w-[640px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt text-xs font-semibold text-text-muted">
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Product
                      </th>
                      {linkedOrder && (
                        <th scope="col" className="px-2 py-1.5 text-right">
                          Ordered
                        </th>
                      )}
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Received
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Unit cost
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Line value
                      </th>
                      <th scope="col" className="px-2 py-1.5" />
                    </tr>
                  </thead>
                  <tbody>
                    {form.items.map((item) => (
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
                        {linkedOrder && (
                          <td className="px-2 py-1.5 text-right">
                            <span className="text-text-muted">
                              {decimalInputValue(item.quantity_ordered) || '0'}
                            </span>
                          </td>
                        )}
                        <td className="px-2 py-1.5">
                          <Input
                            name={`quantity_received-${item.key}`}
                            type="text"
                            inputMode="decimal"
                            value={item.quantity_received}
                            onChange={(event) =>
                              updateLine(
                                item.key,
                                'quantity_received',
                                event.target.value
                              )
                            }
                            className="h-8 w-20 text-right"
                            aria-label="Quantity received"
                          />
                        </td>
                        <td className="px-2 py-1.5">
                          <Input
                            name={`unit_cost-${item.key}`}
                            type="text"
                            inputMode="decimal"
                            value={item.unit_cost}
                            onChange={(event) =>
                              updateLine(item.key, 'unit_cost', event.target.value)
                            }
                            className="h-8 w-28 text-right"
                            aria-label="Unit cost"
                          />
                        </td>
                        <td className="px-2 py-1.5 text-right">
                          <span className="whitespace-nowrap font-medium text-text">
                            {formatMoneyString(
                              toNumber(item.quantity_received) *
                                toNumber(item.unit_cost),
                              CURRENCY
                            )}
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
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="rounded-md border border-dashed border-border bg-surface-alt px-4 py-6 text-center">
                <p className="text-sm font-medium text-text">Nothing received yet</p>
                <p className="mt-1 text-xs text-text-muted">
                  {linkedOrder
                    ? 'The linked order has no outstanding lines to receive.'
                    : 'Search for a product above, or link a purchase order to load its lines.'}
                </p>
              </div>
            )}

            {formErrors.items && (
              <span className="flex items-center gap-1 text-xs text-danger">
                <ion-icon name="alert-circle-outline" aria-hidden="true" />
                {formErrors.items}
              </span>
            )}

            {hasItems && (
              <p className="flex items-center justify-end gap-2 text-sm text-text-muted">
                <span>Estimated received value</span>
                <span className="font-semibold tabular-nums text-text">
                  {formatMoneyString(estimatedValue, CURRENCY)}
                </span>
              </p>
            )}
          </div>
        </FieldGroup>
      </form>
    </Drawer>
  );
}
