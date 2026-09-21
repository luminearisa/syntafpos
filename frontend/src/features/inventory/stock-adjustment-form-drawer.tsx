import { useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { stockAdjustmentApi, warehouseApi } from '@/api/services';
import type {
  AdjustmentReason,
  AdjustmentType,
  ProductLookupResult,
} from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, formatMoneyString, labelFor } from '@/utils/format';
import { ProductPicker } from '@/features/purchasing/product-picker';

interface StockAdjustmentFormDrawerProps {
  open: boolean;
  onClose: () => void;
}

interface AdjustmentLine {
  key: string;
  product_id: number;
  product_name: string;
  product_variant_id: number | null;
  unit_id: number;
  unit_code: string;
  quantity: string;
  unit_cost: string;
}

interface StockAdjustmentFormValues {
  warehouse_id: number | '';
  adjustment_date: string;
  adjustment_type: AdjustmentType | '';
  reason: AdjustmentReason | '';
  notes: string;
  items: AdjustmentLine[];
}

const NEW = 'new';
const CLOSED = 'closed';

const ADJUSTMENT_TYPE_OPTIONS: { label: string; value: AdjustmentType }[] = (
  ['increase', 'decrease'] as const
).map((value) => ({
  value,
  label: value === 'increase' ? 'Stock in (+)' : 'Stock out (−)',
}));

const REASONS: AdjustmentReason[] = [
  'damage',
  'lost',
  'found',
  'expired',
  'counting_error',
  'other',
];

const REASON_OPTIONS = REASONS.map((value) => ({
  value,
  label: labelFor.adjustmentReason(value),
}));

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function extractApiErrors(error: unknown): {
  fieldErrors: Record<string, string>;
  message: string;
} {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string; errors?: Record<string, string[]> } };
    };
    const data = axiosError.response?.data;
    const fieldErrors: Record<string, string> = {};

    if (data?.errors) {
      Object.entries(data.errors).forEach(([key, messages]) => {
        fieldErrors[key] = messages[0] ?? '';
      });
    }

    return { fieldErrors, message: data?.message ?? 'Validation failed' };
  }

  if (error instanceof Error) {
    return { fieldErrors: {}, message: error.message };
  }

  return { fieldErrors: {}, message: 'Something went wrong' };
}

/**
 * Raises a draft adjustment with its item lines. The create endpoint requires
 * at least one line, so the product, quantity and unit cost of every line are
 * captured here; the totals stay server-computed.
 */
export function StockAdjustmentFormDrawer({ open, onClose }: StockAdjustmentFormDrawerProps) {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const scopeWarehouseId = useAuthStore((state) => state.scope.warehouseId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [form, setForm] = useState<StockAdjustmentFormValues>(() => ({
    warehouse_id: scopeWarehouseId ?? '',
    adjustment_date: today(),
    adjustment_type: 'decrease',
    reason: '',
    notes: '',
    items: [],
  }));
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const lineKey = useRef(0);

  const addLine = (product: ProductLookupResult) => {
    setForm((prev) => {
      if (prev.items.some((line) => line.product_id === product.id)) {
        return prev;
      }

      return {
        ...prev,
        items: [
          ...prev.items,
          {
            key: `line-${++lineKey.current}`,
            product_id: product.id,
            product_name: product.name,
            product_variant_id: product.product_variant_id,
            unit_id: product.unit_id ?? 0,
            unit_code: product.unit_code ?? '',
            quantity: '1',
            unit_cost: decimalInputValue(product.cost_price),
          },
        ],
      };
    });
  };

  const updateLine = (key: string, field: 'quantity' | 'unit_cost', value: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((line) =>
        line.key === key ? { ...line, [field]: value } : line
      ),
    }));
  };

  const removeLine = (key: string) => {
    setForm((prev) => ({ ...prev, items: prev.items.filter((line) => line.key !== key) }));
  };

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a fresh adjustment.
  const target = open ? NEW : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm({
      warehouse_id: scopeWarehouseId ?? '',
      adjustment_date: today(),
      adjustment_type: 'decrease',
      reason: '',
      notes: '',
      items: [],
    });
    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: StockAdjustmentFormValues) =>
      stockAdjustmentApi.create({
        company_id: companyId ?? undefined,
        warehouse_id: values.warehouse_id === '' ? undefined : values.warehouse_id,
        adjustment_date: values.adjustment_date,
        adjustment_type: values.adjustment_type || undefined,
        reason: values.reason || undefined,
        notes: values.notes.trim() === '' ? undefined : values.notes,
        items: values.items.map((line) => ({
          product_id: line.product_id,
          product_variant_id: line.product_variant_id,
          unit_id: line.unit_id,
          quantity: line.quantity,
          unit_cost: line.unit_cost,
        })),
      }),
    onSuccess: () => {
      toast({ title: 'Adjustment created', variant: 'success' });
      invalidate(listQueryKeys.stockAdjustments);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: 'Failed to create adjustment',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof StockAdjustmentFormValues>(
    field: K,
    value: StockAdjustmentFormValues[K]
  ) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setFormErrors((prev) => {
      if (!prev[field]) {
        return prev;
      }
      return { ...prev, [field]: '' };
    });
  };

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    mutation.mutate(form);
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="New stock adjustment"
      description="Record a quantity correction for one warehouse, with a reason."
      width="max-w-lg"
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
            form="stock-adjustment-form"
            variant="primary"
            size="sm"
            icon="add-outline"
            loading={mutation.isPending}
            disabled={form.items.length === 0}
          >
            Create adjustment
          </Button>
        </>
      }
    >
      <form
        id="stock-adjustment-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Adjustment details">
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
            wrapperClassName="sm:col-span-2"
            required
          />
          <Input
            label="Adjustment date"
            name="adjustment_date"
            type="date"
            value={form.adjustment_date}
            onChange={(event) => setField('adjustment_date', event.target.value)}
            error={formErrors.adjustment_date}
            required
          />
          <Select
            label="Direction"
            name="adjustment_type"
            options={ADJUSTMENT_TYPE_OPTIONS}
            placeholder="Select direction"
            value={form.adjustment_type}
            onChange={(event) =>
              setField('adjustment_type', (event.target.value || 'decrease') as AdjustmentType)
            }
            error={formErrors.adjustment_type}
            required
          />
          <Select
            label="Reason"
            name="reason"
            options={REASON_OPTIONS}
            placeholder="Select reason"
            value={form.reason}
            onChange={(event) =>
              setField(
                'reason',
                (event.target.value || '') as AdjustmentReason
              )
            }
            error={formErrors.reason}
            required
          />
        </FieldGroup>

        <FieldGroup title="Item lines">
          <div className="flex flex-col gap-3 sm:col-span-2 lg:col-span-3">
            <ProductPicker onSelect={addLine} disabled={mutation.isPending} />

            {form.items.length === 0 ? (
              <p className="text-xs text-text-subtle">
                Search and add at least one product. The sheet cannot be saved
                without a line.
              </p>
            ) : (
              <div className="overflow-x-auto rounded-lg border border-border">
                <table className="min-w-[520px] w-full text-sm">
                  <thead className="bg-surface-alt text-text-subtle">
                    <tr>
                      <th className="px-3 py-2 text-left font-medium">Product</th>
                      <th className="px-3 py-2 text-right font-medium">Qty</th>
                      <th className="px-3 py-2 text-right font-medium">Unit cost</th>
                      <th className="px-3 py-2 text-right font-medium">Value</th>
                      <th className="px-3 py-2" aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {form.items.map((line) => (
                      <tr key={line.key} className="align-middle">
                        <td className="px-3 py-2">
                          <div className="flex flex-col">
                            <span className="font-medium text-text">
                              {line.product_name}
                            </span>
                            <span className="text-xs text-text-subtle">
                              {line.unit_code}
                            </span>
                          </div>
                        </td>
                        <td className="px-3 py-2">
                          <Input
                            name={`qty-${line.key}`}
                            type="number"
                            min="0"
                            step="any"
                            value={line.quantity}
                            onChange={(event) =>
                              updateLine(line.key, 'quantity', event.target.value)
                            }
                            disabled={mutation.isPending}
                            className="h-8 w-24 text-right tabular-nums"
                            aria-label={`Quantity for ${line.product_name}`}
                          />
                        </td>
                        <td className="px-3 py-2">
                          <Input
                            name={`cost-${line.key}`}
                            type="number"
                            min="0"
                            step="any"
                            value={line.unit_cost}
                            onChange={(event) =>
                              updateLine(line.key, 'unit_cost', event.target.value)
                            }
                            disabled={mutation.isPending}
                            className="h-8 w-28 text-right tabular-nums"
                            aria-label={`Unit cost for ${line.product_name}`}
                          />
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums text-text-muted">
                          {formatMoneyString(
                            (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0)
                          )}
                        </td>
                        <td className="px-3 py-2 text-right">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            icon="trash-outline"
                            onClick={() => removeLine(line.key)}
                            disabled={mutation.isPending}
                            aria-label={`Remove ${line.product_name}`}
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {formErrors.items ? (
              <p className="text-xs text-danger">{formErrors.items}</p>
            ) : null}
          </div>
        </FieldGroup>

        <FieldGroup title="Notes">
          <Textarea
            label="Notes"
            name="notes"
            value={form.notes}
            onChange={(event) => setField('notes', event.target.value)}
            error={formErrors.notes}
            placeholder="Explain what happened to this stock..."
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
