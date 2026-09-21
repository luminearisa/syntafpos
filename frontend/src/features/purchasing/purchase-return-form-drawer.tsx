import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
  goodsReceiptApi,
  purchaseReturnApi,
  supplierApi,
  warehouseApi,
} from '@/api/services';
import type {
  ProductLookupResult,
  PurchaseReturn,
  PurchaseReturnItem,
} from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, formatDate, formatMoneyString } from '@/utils/format';
import { ProductPicker } from './product-picker';
import { emptyToNull, extractApiErrors, toNumber } from './shared';

interface PurchaseReturnFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: PurchaseReturn | null;
}

interface ReturnLineItem {
  key: string;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  product_name: string;
  product_sku: string;
  unit_code: string;
  quantity: string;
  unit_cost: string;
  reason: string;
}

interface ReturnFormValues {
  supplier_id: number | '';
  warehouse_id: number | '';
  goods_receipt_id: number | '';
  return_date: string;
  notes: string;
  items: ReturnLineItem[];
}

const NEW = 'new';
const CLOSED = 'closed';

const CURRENCY = 'IDR';

let lineKeySeed = 0;

function nextLineKey(): string {
  lineKeySeed += 1;
  return `prt-line-${lineKeySeed}`;
}

function lineFromApi(item: PurchaseReturnItem): ReturnLineItem {
  return {
    key: nextLineKey(),
    product_id: item.product_id,
    product_variant_id: item.product_variant_id,
    unit_id: item.unit_id,
    product_name: item.product?.name ?? `Product #${item.product_id}`,
    product_sku: item.product?.sku ?? '',
    unit_code: item.unit?.code ?? '',
    quantity: decimalInputValue(item.quantity),
    unit_cost: decimalInputValue(item.unit_cost),
    reason: item.notes ?? '',
  };
}

function emptyForm(): ReturnFormValues {
  return {
    supplier_id: '',
    warehouse_id: '',
    goods_receipt_id: '',
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
    items: [],
  };
}

export function PurchaseReturnFormDrawer({
  open,
  onClose,
  initial,
}: PurchaseReturnFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const scopeCompanyId = useAuthStore((state) => state.scope.companyId);
  const companyId = isEditing ? initial.company_id : scopeCompanyId;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: supplierOptionsData } = useQuery({
    queryKey: ['suppliers', 'prt-drawer-options', companyId],
    queryFn: () => supplierApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const supplierOptions = (supplierOptionsData?.data ?? []).map((supplier) => ({
    label: supplier.name,
    value: supplier.id,
  }));

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'prt-drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  // Goods may only be returned against a posted receipt, so the picker is
  // limited to those.
  const { data: receiptOptionsData } = useQuery({
    queryKey: ['goods-receipts', 'posted', companyId],
    queryFn: () =>
      goodsReceiptApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null && !isEditing,
  });
  const receiptOptions = (receiptOptionsData?.data ?? [])
    .filter((receipt) => receipt.status === 'posted')
    .map((receipt) => ({
      label: `${receipt.number} · ${formatDate(receipt.receipt_date)}`,
      value: receipt.id,
    }));

  const [form, setForm] = useState<ReturnFormValues>(() => emptyForm());
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
        goods_receipt_id: initial.goods_receipt_id ?? '',
        return_date: initial.return_date ?? new Date().toISOString().slice(0, 10),
        notes: initial.notes ?? '',
        items: (initial.items ?? []).map(lineFromApi),
      });
    } else {
      setForm(emptyForm());
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: ReturnFormValues) => {
      const payload = {
        company_id: companyId,
        branch_id: null,
        warehouse_id: values.warehouse_id,
        supplier_id: values.supplier_id,
        goods_receipt_id: values.goods_receipt_id || null,
        purchase_order_id: null,
        return_date: values.return_date,
        notes: emptyToNull(values.notes),
        items: values.items.map((item) => ({
          goods_receipt_item_id: null,
          product_id: item.product_id,
          product_variant_id: item.product_variant_id,
          unit_id: item.unit_id,
          quantity: item.quantity || '0',
          unit_cost: item.unit_cost || null,
          notes: emptyToNull(item.reason),
        })),
      };

      if (isEditing) {
        return purchaseReturnApi.update(id, payload);
      }

      return purchaseReturnApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Purchase return updated' : 'Purchase return created',
        variant: 'success',
      });
      invalidate(listQueryKeys.purchaseReturns);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing
          ? 'Failed to update purchase return'
          : 'Failed to create purchase return',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof ReturnFormValues>(
    field: K,
    value: ReturnFormValues[K]
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
          quantity: '1',
          unit_cost: decimalInputValue(product.cost_price),
          reason: '',
        },
      ],
    }));
  };

  const updateLine = <K extends keyof ReturnLineItem>(
    key: string,
    field: K,
    value: ReturnLineItem[K]
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

  // Display-only preview (spec §46): the posted total is recomputed server-side,
  // so this is a convenience for the user, never the source of truth.
  const estimatedTotal = form.items.reduce(
    (acc, item) => acc + toNumber(item.quantity) * toNumber(item.unit_cost),
    0
  );

  const hasItems = form.items.length > 0;
  const submitDisabled = mutation.isPending || !hasItems;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? `Edit ${initial?.number}` : 'New purchase return'}
      description={
        isEditing
          ? 'Only a draft return can be edited. Posting it moves stock back out of the ledger.'
          : 'Send goods back to a supplier. Posting a return moves stock out of the ledger.'
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
            form="purchase-return-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
            disabled={submitDisabled}
          >
            {isEditing ? 'Save changes' : 'Create draft return'}
          </Button>
        </>
      }
    >
      <form
        id="purchase-return-form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate(form);
        }}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Return details">
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
              label="Goods receipt"
              name="goods_receipt_id"
              options={receiptOptions}
              placeholder="Unlinked return"
              value={form.goods_receipt_id}
              onChange={(event) =>
                setField(
                  'goods_receipt_id',
                  event.target.value === '' ? '' : Number(event.target.value)
                )
              }
              error={formErrors.goods_receipt_id}
              disabled={isEditing || mutation.isPending}
            />
            {isEditing && (
              <p className="text-xs text-text-subtle">
                The linked receipt cannot be changed.
              </p>
            )}
          </div>
          <Input
            label="Return date"
            name="return_date"
            type="date"
            value={form.return_date}
            onChange={(event) => setField('return_date', event.target.value)}
            error={formErrors.return_date}
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

        <FieldGroup title="Returned items">
          <div className="flex flex-col gap-3 sm:col-span-2 lg:col-span-3">
            <ProductPicker
              onSelect={addLine}
              disabled={mutation.isPending}
              placeholder="Search products to add a line..."
            />

            {hasItems ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full min-w-[720px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt text-xs font-semibold text-text-muted">
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Product
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Qty
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Unit cost
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Reason
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
                        <td className="px-2 py-1.5">
                          <Input
                            name={`reason-${item.key}`}
                            type="text"
                            value={item.reason}
                            onChange={(event) =>
                              updateLine(item.key, 'reason', event.target.value)
                            }
                            className="h-8 w-40"
                            aria-label="Return reason"
                            placeholder="e.g. damaged"
                          />
                        </td>
                        <td className="px-2 py-1.5 text-right">
                          <span className="whitespace-nowrap font-medium text-text">
                            {formatMoneyString(
                              toNumber(item.quantity) * toNumber(item.unit_cost),
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
                <p className="text-sm font-medium text-text">Nothing to return</p>
                <p className="mt-1 text-xs text-text-muted">
                  Search for a product above to add the first returned line.
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
                <span>Estimated return value</span>
                <span className="font-semibold tabular-nums text-text">
                  {formatMoneyString(estimatedTotal, CURRENCY)}
                </span>
              </p>
            )}
          </div>
        </FieldGroup>
      </form>
    </Drawer>
  );
}
