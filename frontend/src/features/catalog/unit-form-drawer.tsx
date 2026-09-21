import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { unitApi } from '@/api/services';
import type { Unit, UnitType } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue, labelFor } from '@/utils/format';

interface UnitFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Unit | null;
}

interface UnitFormValues {
  code: string;
  name: string;
  description: string;
  unit_type: UnitType | '';
  is_base: 'yes' | 'no';
  base_unit_id: number | '';
  base_factor: string;
  status: 'active' | 'inactive';
}

const NEW = 'new';
const CLOSED = 'closed';

const unitTypeOptions: { label: string; value: UnitType }[] = (
  ['quantity', 'length', 'weight', 'volume', 'area'] as const
).map((value) => ({ value, label: labelFor.unitType(value) }));

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
];

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

export function UnitFormDrawer({ open, onClose, initial }: UnitFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  // Base units are the natural conversion anchors, so only offer those as the
  // parent of another unit. A unit is never its own base.
  const { data: baseUnitData } = useQuery({
    queryKey: ['units', 'drawer-options', companyId],
    queryFn: () =>
      unitApi.list({ company_id: companyId ?? undefined, per_page: 200 }),
    enabled: open,
  });
  const baseUnitOptions = (baseUnitData?.data ?? [])
    .filter((unit) => unit.is_base && unit.id !== initial?.id)
    .map((unit) => ({ label: `${unit.code} — ${unit.name}`, value: unit.id }));

  const [form, setForm] = useState<UnitFormValues>({
    code: '',
    name: '',
    description: '',
    unit_type: '',
    is_base: 'no',
    base_unit_id: '',
    base_factor: '',
    status: 'active',
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
        description: initial.description ?? '',
        unit_type: initial.unit_type ?? '',
        is_base: initial.is_base ? 'yes' : 'no',
        base_unit_id: initial.base_unit_id ?? '',
        base_factor: decimalInputValue(initial.base_factor),
        status: initial.status === 'inactive' ? 'inactive' : 'active',
      });
    } else {
      setForm({
        code: '',
        name: '',
        description: '',
        unit_type: '',
        is_base: 'no',
        base_unit_id: '',
        base_factor: '',
        status: 'active',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: UnitFormValues) => {
      const payload = {
        code: values.code,
        name: values.name,
        description: emptyToNull(values.description),
        unit_type: values.unit_type || null,
        is_base: values.is_base === 'yes',
        base_unit_id: values.base_unit_id === '' ? null : values.base_unit_id,
        base_factor: values.base_factor.trim() === '' ? null : values.base_factor,
        status: values.status,
      };

      if (isEditing) {
        return unitApi.update(id, payload);
      }

      return unitApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Unit updated' : 'Unit created',
        variant: 'success',
      });
      invalidate(listQueryKeys.units);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update unit' : 'Failed to create unit',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof UnitFormValues>(
    field: K,
    value: UnitFormValues[K]
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
      title={isEditing ? 'Edit unit' : 'New unit'}
      description={
        isEditing
          ? 'Update the unit of measure and its conversion anchor.'
          : 'Define a unit of measure used for buying and selling products.'
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
            form="unit-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create unit'}
          </Button>
        </>
      }
    >
      <form id="unit-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FieldGroup title="Details">
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique within the company, e.g. PCS"
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
          <Select
            label="Unit type"
            name="unit_type"
            options={unitTypeOptions}
            placeholder="Select type"
            value={form.unit_type}
            onChange={(event) =>
              setField('unit_type', event.target.value as UnitType | '')
            }
            error={formErrors.unit_type}
          />
          <Select
            label="Status"
            name="status"
            options={statusOptions}
            value={form.status}
            onChange={(event) =>
              setField('status', event.target.value === 'inactive' ? 'inactive' : 'active')
            }
            error={formErrors.status}
          />
          <Textarea
            label="Description"
            name="description"
            value={form.description}
            onChange={(event) => setField('description', event.target.value)}
            error={formErrors.description}
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>

        <FieldGroup title="Conversion">
          <Select
            label="Is base unit"
            name="is_base"
            options={yesNoOptions}
            value={form.is_base}
            onChange={(event) =>
              setField('is_base', event.target.value === 'yes' ? 'yes' : 'no')
            }
            error={formErrors.is_base}
          />
          <Select
            label="Base unit"
            name="base_unit_id"
            options={baseUnitOptions}
            placeholder="No base unit"
            value={form.base_unit_id}
            onChange={(event) =>
              setField(
                'base_unit_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.base_unit_id}
          />
          <Input
            label="Base factor"
            name="base_factor"
            inputMode="decimal"
            value={form.base_factor}
            onChange={(event) => setField('base_factor', event.target.value)}
            error={formErrors.base_factor}
            hint="How many of this unit make one base unit"
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
