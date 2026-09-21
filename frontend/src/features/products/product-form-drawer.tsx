import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { productApi } from '@/api/services';
import type { Product } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';
import { decimalInputValue, formatMoneyString } from '@/utils/format';

interface ProductFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Product | null;
}

interface ProductFormValues {
  cost_price: string;
  selling_price: string;
  minimum_selling_price: string;
  is_active: 'yes' | 'no';
  track_inventory: 'yes' | 'no';
  minimum_stock: string;
  maximum_stock: string;
  reorder_point: string;
  reorder_quantity: string;
}

const NEW = 'new';
const CLOSED = 'closed';

const yesNoOptions = [
  { label: 'Yes', value: 'yes' },
  { label: 'No', value: 'no' },
];

function emptyToNull(value: string): string | null {
  return value.trim() === '' ? null : value;
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

export function ProductFormDrawer({
  open,
  onClose,
  initial,
}: ProductFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<ProductFormValues>({
    cost_price: '',
    selling_price: '',
    minimum_selling_price: '',
    is_active: 'yes',
    track_inventory: 'yes',
    minimum_stock: '',
    maximum_stock: '',
    reorder_point: '',
    reorder_quantity: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    if (initial) {
      setForm({
        cost_price: decimalInputValue(initial.cost_price),
        selling_price: decimalInputValue(initial.selling_price),
        minimum_selling_price: decimalInputValue(initial.minimum_selling_price),
        is_active: initial.is_active ? 'yes' : 'no',
        track_inventory: initial.track_inventory ? 'yes' : 'no',
        minimum_stock: decimalInputValue(initial.minimum_stock),
        maximum_stock: decimalInputValue(initial.maximum_stock),
        reorder_point: decimalInputValue(initial.reorder_point),
        reorder_quantity: decimalInputValue(initial.reorder_quantity),
      });
    } else {
      setForm({
        cost_price: '',
        selling_price: '',
        minimum_selling_price: '',
        is_active: 'yes',
        track_inventory: 'yes',
        minimum_stock: '',
        maximum_stock: '',
        reorder_point: '',
        reorder_quantity: '',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: ProductFormValues) => {
      // Deliberately narrow: identity, variants and barcodes are managed
      // elsewhere, so this drawer only persists pricing and stock policy.
      const payload = {
        cost_price: values.cost_price,
        selling_price: values.selling_price,
        minimum_selling_price: emptyToNull(values.minimum_selling_price),
        is_active: values.is_active === 'yes',
        track_inventory: values.track_inventory === 'yes',
        minimum_stock: values.minimum_stock,
        maximum_stock: emptyToNull(values.maximum_stock),
        reorder_point: values.reorder_point,
        reorder_quantity: emptyToNull(values.reorder_quantity),
      };

      return isEditing ? productApi.update(id, payload) : productApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Product updated' : 'Product created',
        variant: 'success',
      });
      invalidate(listQueryKeys.products);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update product' : 'Failed to create product',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof ProductFormValues>(
    field: K,
    value: ProductFormValues[K]
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

  // Show live figures straight from the record; no local arithmetic on money.
  const marginPercent = initial?.costing?.estimated_margin_percent;
  const lastPurchaseCost = initial?.costing?.last_purchase_cost;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? 'Quick edit' : 'New product'}
      description={
        isEditing
          ? 'Adjust pricing, status and reorder rules. Variants and barcodes are managed separately.'
          : 'Register a product with its pricing and stock policy.'
      }
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
            form="product-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create product'}
          </Button>
        </>
      }
    >
      <form
        id="product-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        {isEditing && initial && (
          <div className="rounded-lg border border-border bg-surface-alt p-3">
            <div className="flex items-center gap-2">
              <span className="font-mono text-xs text-text-muted">
                {initial.sku}
              </span>
              {initial.barcode && (
                <span className="font-mono text-xs text-text-subtle">
                  {initial.barcode}
                </span>
              )}
            </div>
            <p className="mt-0.5 text-sm font-medium text-text">
              {initial.name}
            </p>
            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-text-muted">
              {initial.category && (
                <span>Category: {initial.category.name}</span>
              )}
              {initial.brand && <span>Brand: {initial.brand.name}</span>}
              {initial.default_unit && (
                <span>Unit: {initial.default_unit.code}</span>
              )}
            </div>
          </div>
        )}

        <FieldGroup title="Pricing">
          <Input
            label="Cost price"
            name="cost_price"
            inputMode="decimal"
            value={form.cost_price}
            onChange={(event) => setField('cost_price', event.target.value)}
            error={formErrors.cost_price}
            required
          />
          <Input
            label="Selling price"
            name="selling_price"
            inputMode="decimal"
            value={form.selling_price}
            onChange={(event) => setField('selling_price', event.target.value)}
            error={formErrors.selling_price}
            required
          />
          <Input
            label="Minimum selling price"
            name="minimum_selling_price"
            inputMode="decimal"
            value={form.minimum_selling_price}
            onChange={(event) =>
              setField('minimum_selling_price', event.target.value)
            }
            error={formErrors.minimum_selling_price}
          />
          {isEditing && marginPercent && (
            <div className="flex flex-col gap-1 sm:col-span-2 lg:col-span-3">
              <span className="text-xs font-medium text-text-muted">
                Current margin
              </span>
              <span className="text-sm text-text">
                {formatMoneyString(initial?.selling_price)} sells against{' '}
                {formatMoneyString(lastPurchaseCost)} cost (
                {decimalInputValue(marginPercent)}%)
              </span>
            </div>
          )}
        </FieldGroup>

        <FieldGroup title="Status">
          <Select
            label="Active"
            name="is_active"
            options={yesNoOptions}
            value={form.is_active}
            onChange={(event) =>
              setField('is_active', event.target.value === 'yes' ? 'yes' : 'no')
            }
            error={formErrors.is_active}
          />
          <Select
            label="Track inventory"
            name="track_inventory"
            options={yesNoOptions}
            value={form.track_inventory}
            onChange={(event) =>
              setField(
                'track_inventory',
                event.target.value === 'yes' ? 'yes' : 'no'
              )
            }
            error={formErrors.track_inventory}
          />
        </FieldGroup>

        <FieldGroup title="Reorder rules">
          <Input
            label="Minimum stock"
            name="minimum_stock"
            inputMode="decimal"
            value={form.minimum_stock}
            onChange={(event) => setField('minimum_stock', event.target.value)}
            error={formErrors.minimum_stock}
          />
          <Input
            label="Maximum stock"
            name="maximum_stock"
            inputMode="decimal"
            value={form.maximum_stock}
            onChange={(event) => setField('maximum_stock', event.target.value)}
            error={formErrors.maximum_stock}
          />
          <Input
            label="Reorder point"
            name="reorder_point"
            inputMode="decimal"
            value={form.reorder_point}
            onChange={(event) => setField('reorder_point', event.target.value)}
            error={formErrors.reorder_point}
          />
          <Input
            label="Reorder quantity"
            name="reorder_quantity"
            inputMode="decimal"
            value={form.reorder_quantity}
            onChange={(event) =>
              setField('reorder_quantity', event.target.value)
            }
            error={formErrors.reorder_quantity}
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
