import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { purchaseRequestApi, warehouseApi } from '@/api/services';
import type { ProductLookupResult, PurchaseRequest, PurchaseRequestItem } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, formatMoneyString } from '@/utils/format';
import { ProductPicker } from './product-picker';
import { emptyToNull, extractApiErrors, toNumber } from './shared';

interface PurchaseRequestFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: PurchaseRequest | null;
}

interface RequestLineItem {
  key: string;
  product_id: number;
  product_variant_id: number | null;
  unit_id: number | null;
  product_name: string;
  product_sku: string;
  unit_code: string;
  quantity: string;
  unit_price: string;
}

interface RequestFormValues {
  warehouse_id: number | '';
  request_date: string;
  required_date: string;
  notes: string;
  items: RequestLineItem[];
}

const NEW = 'new';
const CLOSED = 'closed';

const CURRENCY = 'IDR';

let lineKeySeed = 0;

function nextLineKey(): string {
  lineKeySeed += 1;
  return `pr-line-${lineKeySeed}`;
}

/**
 * The API item carries no price (a request asks for quantity, not cost), so an
 * edit opens its lines without one; the optional estimate is a buyer's note.
 */
function lineFromApi(item: PurchaseRequestItem): RequestLineItem {
  return {
    key: nextLineKey(),
    product_id: item.product_id,
    product_variant_id: item.product_variant_id,
    unit_id: item.unit_id,
    product_name: item.product?.name ?? `Product #${item.product_id}`,
    product_sku: item.product?.sku ?? '',
    unit_code: item.unit?.code ?? '',
    quantity: decimalInputValue(item.quantity),
    unit_price: '',
  };
}

function emptyForm(): RequestFormValues {
  return {
    warehouse_id: '',
    request_date: new Date().toISOString().slice(0, 10),
    required_date: '',
    notes: '',
    items: [],
  };
}

export function PurchaseRequestFormDrawer({
  open,
  onClose,
  initial,
}: PurchaseRequestFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const scopeCompanyId = useAuthStore((state) => state.scope.companyId);
  const companyId = isEditing ? initial.company_id : scopeCompanyId;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'pr-drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [form, setForm] = useState<RequestFormValues>(() => emptyForm());
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);

    if (initial) {
      setForm({
        warehouse_id: initial.warehouse_id,
        request_date: initial.request_date ?? new Date().toISOString().slice(0, 10),
        required_date: initial.required_date ?? '',
        notes: initial.notes ?? '',
        items: (initial.items ?? []).map(lineFromApi),
      });
    } else {
      setForm(emptyForm());
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: RequestFormValues) => {
      const payload = {
        company_id: companyId,
        branch_id: null,
        warehouse_id: values.warehouse_id,
        request_date: values.request_date,
        required_date: emptyToNull(values.required_date),
        notes: emptyToNull(values.notes),
        items: values.items.map((item) => ({
          product_id: item.product_id,
          product_variant_id: item.product_variant_id,
          unit_id: item.unit_id,
          quantity: item.quantity,
          // An estimated price is a buyer's note only; the request itself
          // never carries an authoritative cost.
          unit_price: item.unit_price || '0',
        })),
      };

      if (isEditing) {
        return purchaseRequestApi.update(id, payload);
      }

      return purchaseRequestApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Purchase request updated' : 'Purchase request created',
        variant: 'success',
      });
      invalidate(listQueryKeys.purchaseRequests);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing
          ? 'Failed to update purchase request'
          : 'Failed to create purchase request',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof RequestFormValues>(
    field: K,
    value: RequestFormValues[K]
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
          unit_price: decimalInputValue(product.cost_price),
        },
      ],
    }));
  };

  const updateLine = <K extends keyof RequestLineItem>(
    key: string,
    field: K,
    value: RequestLineItem[K]
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

  // Display-only preview (spec §46): the server recalculates any stored figure,
  // so this is a convenience for the user, never the source of truth.
  const estimatedTotal = form.items.reduce(
    (acc, item) => acc + toNumber(item.quantity) * toNumber(item.unit_price),
    0
  );

  const hasItems = form.items.length > 0;
  const submitDisabled = mutation.isPending || !hasItems;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? `Edit ${initial?.number}` : 'New purchase request'}
      description={
        isEditing
          ? 'Only an open request can be edited. Submit it to start approval.'
          : 'Ask a warehouse to restock. Submit it to start the approval workflow.'
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
            form="purchase-request-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
            disabled={submitDisabled}
          >
            {isEditing ? 'Save changes' : 'Create request'}
          </Button>
        </>
      }
    >
      <form
        id="purchase-request-form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate(form);
        }}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Request details">
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
            label="Request date"
            name="request_date"
            type="date"
            value={form.request_date}
            onChange={(event) => setField('request_date', event.target.value)}
            error={formErrors.request_date}
            required
          />
          <Input
            label="Required date"
            name="required_date"
            type="date"
            value={form.required_date}
            onChange={(event) => setField('required_date', event.target.value)}
            error={formErrors.required_date}
            hint="Optional"
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
                <table className="w-full min-w-[640px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b border-border bg-surface-alt text-xs font-semibold text-text-muted">
                      <th scope="col" className="px-2 py-1.5 text-left">
                        Product
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Qty
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Est. unit price
                      </th>
                      <th scope="col" className="px-2 py-1.5 text-right">
                        Est. total
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
                            name={`unit_price-${item.key}`}
                            type="text"
                            inputMode="decimal"
                            value={item.unit_price}
                            onChange={(event) =>
                              updateLine(item.key, 'unit_price', event.target.value)
                            }
                            className="h-8 w-28 text-right"
                            aria-label="Estimated unit price"
                          />
                        </td>
                        <td className="px-2 py-1.5 text-right">
                          <span className="whitespace-nowrap font-medium text-text">
                            {formatMoneyString(
                              toNumber(item.quantity) * toNumber(item.unit_price),
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
                <p className="text-sm font-medium text-text">No items requested</p>
                <p className="mt-1 text-xs text-text-muted">
                  Search for a product above to add the first requested line.
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
                <span>Estimated value</span>
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
