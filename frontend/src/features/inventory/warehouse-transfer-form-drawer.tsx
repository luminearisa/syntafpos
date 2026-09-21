import { useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { warehouseApi, warehouseTransferApi } from '@/api/services';
import type { ProductLookupResult } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { ProductPicker } from '@/features/purchasing/product-picker';

interface WarehouseTransferFormDrawerProps {
  open: boolean;
  onClose: () => void;
}

interface TransferLine {
  key: string;
  product_id: number;
  product_name: string;
  product_variant_id: number | null;
  unit_id: number;
  unit_code: string;
  quantity: string;
}

interface WarehouseTransferFormValues {
  from_warehouse_id: number | '';
  to_warehouse_id: number | '';
  transfer_date: string;
  notes: string;
  items: TransferLine[];
}

const NEW = 'new';
const CLOSED = 'closed';

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
 * Raises a draft transfer with its item lines. The create endpoint requires at
 * least one line, so the product and quantity of every line are captured here.
 */
export function WarehouseTransferFormDrawer({
  open,
  onClose,
}: WarehouseTransferFormDrawerProps) {
  const companyId = useAuthStore((state) => state.scope.companyId);
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

  const [form, setForm] = useState<WarehouseTransferFormValues>(() => ({
    from_warehouse_id: '',
    to_warehouse_id: '',
    transfer_date: today(),
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
          },
        ],
      };
    });
  };

  const updateLineQuantity = (key: string, value: string) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((line) =>
        line.key === key ? { ...line, quantity: value } : line
      ),
    }));
  };

  const removeLine = (key: string) => {
    setForm((prev) => ({ ...prev, items: prev.items.filter((line) => line.key !== key) }));
  };

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a fresh transfer.
  const target = open ? NEW : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm({
      from_warehouse_id: '',
      to_warehouse_id: '',
      transfer_date: today(),
      notes: '',
      items: [],
    });
    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: WarehouseTransferFormValues) => {
      const warehouseId =
        values.from_warehouse_id === '' ? undefined : values.from_warehouse_id;
      const destinationId =
        values.to_warehouse_id === '' ? undefined : values.to_warehouse_id;

      const payload: Record<string, unknown> = {
        company_id: companyId ?? undefined,
        from_warehouse_id: warehouseId,
        to_warehouse_id: destinationId,
        transfer_date: values.transfer_date,
        notes: values.notes.trim() === '' ? undefined : values.notes,
      };

      // Mirror the backend rule: debiting and crediting one balance is a no-op.
      if (
        warehouseId !== undefined &&
        destinationId !== undefined &&
        warehouseId === destinationId
      ) {
        return Promise.reject(
          new Error('The destination warehouse must differ from the source warehouse.')
        );
      }

      return warehouseTransferApi.create({
        ...payload,
        items: values.items.map((line) => ({
          product_id: line.product_id,
          product_variant_id: line.product_variant_id,
          unit_id: line.unit_id,
          quantity: line.quantity,
        })),
      });
    },
    onSuccess: () => {
      toast({ title: 'Transfer created', variant: 'success' });
      invalidate(listQueryKeys.warehouseTransfers);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: 'Failed to create transfer',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof WarehouseTransferFormValues>(
    field: K,
    value: WarehouseTransferFormValues[K]
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
      title="New warehouse transfer"
      description="Move stock between two warehouses. Lines are entered on the transfer itself."
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
            form="warehouse-transfer-form"
            variant="primary"
            size="sm"
            icon="add-outline"
            loading={mutation.isPending}
            disabled={form.items.length === 0}
          >
            Create transfer
          </Button>
        </>
      }
    >
      <form
        id="warehouse-transfer-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Route">
          <Select
            label="From warehouse"
            name="from_warehouse_id"
            options={warehouseOptions}
            placeholder="Select source"
            value={form.from_warehouse_id}
            onChange={(event) =>
              setField(
                'from_warehouse_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.from_warehouse_id}
            required
          />
          <Select
            label="To warehouse"
            name="to_warehouse_id"
            options={warehouseOptions}
            placeholder="Select destination"
            value={form.to_warehouse_id}
            onChange={(event) =>
              setField(
                'to_warehouse_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.to_warehouse_id}
            required
          />
          <Input
            label="Transfer date"
            name="transfer_date"
            type="date"
            value={form.transfer_date}
            onChange={(event) => setField('transfer_date', event.target.value)}
            error={formErrors.transfer_date}
            wrapperClassName="sm:col-span-2"
            required
          />
        </FieldGroup>

        <FieldGroup title="Item lines">
          <div className="flex flex-col gap-3 sm:col-span-2 lg:col-span-3">
            <ProductPicker onSelect={addLine} disabled={mutation.isPending} />

            {form.items.length === 0 ? (
              <p className="text-xs text-text-subtle">
                Search and add at least one product. The transfer cannot be
                saved without a line.
              </p>
            ) : (
              <div className="overflow-x-auto rounded-lg border border-border">
                <table className="min-w-[420px] w-full text-sm">
                  <thead className="bg-surface-alt text-text-subtle">
                    <tr>
                      <th className="px-3 py-2 text-left font-medium">Product</th>
                      <th className="px-3 py-2 text-right font-medium">Qty</th>
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
                              updateLineQuantity(line.key, event.target.value)
                            }
                            disabled={mutation.isPending}
                            className="h-8 w-24 text-right tabular-nums"
                            aria-label={`Quantity for ${line.product_name}`}
                          />
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
            placeholder="Anything the receiving warehouse should know..."
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
