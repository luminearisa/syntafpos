import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { taxApi } from '@/api/services';
import type { Tax, TaxType } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';
import { decimalInputValue, labelFor } from '@/utils/format';

interface TaxFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Tax | null;
}

interface TaxFormValues {
  code: string;
  name: string;
  rate: string;
  type: TaxType | '';
  is_default: 'yes' | 'no';
  is_active: 'yes' | 'no';
}

const NEW = 'new';
const CLOSED = 'closed';

const taxTypeOptions: { label: string; value: TaxType }[] = (
  ['inclusive', 'exclusive'] as const
).map((value) => ({ value, label: labelFor.taxType(value) }));

const yesNoOptions = [
  { label: 'Yes', value: 'yes' },
  { label: 'No', value: 'no' },
];

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

export function TaxFormDrawer({ open, onClose, initial }: TaxFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<TaxFormValues>({
    code: '',
    name: '',
    rate: '',
    type: '',
    is_default: 'no',
    is_active: 'yes',
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
        code: initial.code ?? '',
        name: initial.name ?? '',
        rate: decimalInputValue(initial.rate),
        type: initial.type ?? '',
        is_default: initial.is_default ? 'yes' : 'no',
        is_active: initial.is_active ? 'yes' : 'no',
      });
    } else {
      setForm({
        code: '',
        name: '',
        rate: '',
        type: '',
        is_default: 'no',
        is_active: 'yes',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: TaxFormValues) => {
      const payload = {
        code: values.code,
        name: values.name,
        rate: values.rate,
        type: values.type || null,
        is_default: values.is_default === 'yes',
        is_active: values.is_active === 'yes',
      };

      return isEditing ? taxApi.update(id, payload) : taxApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Tax updated' : 'Tax created',
        variant: 'success',
      });
      invalidate(listQueryKeys.taxes);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update tax' : 'Failed to create tax',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof TaxFormValues>(
    field: K,
    value: TaxFormValues[K]
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
      title={isEditing ? 'Edit tax' : 'New tax'}
      description={
        isEditing
          ? 'Update the tax rate applied to products and documents.'
          : 'Define a tax rate that can be attached to products.'
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
            form="tax-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create tax'}
          </Button>
        </>
      }
    >
      <form id="tax-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FieldGroup title="Details">
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique within the company, e.g. PPN"
            required
          />
          <Input
            label="Name"
            name="name"
            value={form.name}
            onChange={(event) => setField('name', event.target.value)}
            error={formErrors.name}
            required
          />
          <Input
            label="Rate (%)"
            name="rate"
            inputMode="decimal"
            value={form.rate}
            onChange={(event) => setField('rate', event.target.value)}
            error={formErrors.rate}
            hint="Percentage, e.g. 11 for 11%"
            required
          />
          <Select
            label="Type"
            name="type"
            options={taxTypeOptions}
            placeholder="Select type"
            value={form.type}
            onChange={(event) =>
              setField('type', event.target.value as TaxType | '')
            }
            error={formErrors.type}
          />
          <Select
            label="Default"
            name="is_default"
            options={yesNoOptions}
            value={form.is_default}
            onChange={(event) =>
              setField('is_default', event.target.value === 'yes' ? 'yes' : 'no')
            }
            error={formErrors.is_default}
          />
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
        </FieldGroup>
      </form>
    </Drawer>
  );
}
