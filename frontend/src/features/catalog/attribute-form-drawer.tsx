import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { attributeApi } from '@/api/services';
import type { Attribute } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';

interface AttributeFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Attribute | null;
}

interface AttributeFormValues {
  code: string;
  name: string;
  data_type: string;
  is_required: 'yes' | 'no';
  is_filterable: 'yes' | 'no';
}

const NEW = 'new';
const CLOSED = 'closed';

const dataTypeOptions = [
  { label: 'Text', value: 'text' },
  { label: 'Number', value: 'number' },
  { label: 'Boolean', value: 'boolean' },
  { label: 'Date', value: 'date' },
  { label: 'Select (single)', value: 'select' },
  { label: 'Multi-select', value: 'multiselect' },
  { label: 'Color', value: 'color' },
];

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

export function AttributeFormDrawer({
  open,
  onClose,
  initial,
}: AttributeFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<AttributeFormValues>({
    code: '',
    name: '',
    data_type: 'text',
    is_required: 'no',
    is_filterable: 'no',
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
        data_type: initial.data_type || 'text',
        is_required: initial.is_required ? 'yes' : 'no',
        is_filterable: initial.is_filterable ? 'yes' : 'no',
      });
    } else {
      setForm({
        code: '',
        name: '',
        data_type: 'text',
        is_required: 'no',
        is_filterable: 'no',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: AttributeFormValues) => {
      const payload = {
        code: values.code,
        name: values.name,
        data_type: values.data_type,
        is_required: values.is_required === 'yes',
        is_filterable: values.is_filterable === 'yes',
      };

      return isEditing
        ? attributeApi.update(id, payload)
        : attributeApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Attribute updated' : 'Attribute created',
        variant: 'success',
      });
      invalidate(listQueryKeys.attributes);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update attribute' : 'Failed to create attribute',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof AttributeFormValues>(
    field: K,
    value: AttributeFormValues[K]
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
      title={isEditing ? 'Edit attribute' : 'New attribute'}
      description={
        isEditing
          ? 'Update the attribute definition.'
          : 'Define a product attribute such as colour, size or material.'
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
            form="attribute-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create attribute'}
          </Button>
        </>
      }
    >
      <form
        id="attribute-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Details">
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique within the company, e.g. COLOUR"
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
            label="Data type"
            name="data_type"
            options={dataTypeOptions}
            value={form.data_type}
            onChange={(event) => setField('data_type', event.target.value)}
            error={formErrors.data_type}
          />
          <Select
            label="Required"
            name="is_required"
            options={yesNoOptions}
            value={form.is_required}
            onChange={(event) =>
              setField('is_required', event.target.value === 'yes' ? 'yes' : 'no')
            }
            error={formErrors.is_required}
          />
          <Select
            label="Filterable"
            name="is_filterable"
            options={yesNoOptions}
            value={form.is_filterable}
            onChange={(event) =>
              setField(
                'is_filterable',
                event.target.value === 'yes' ? 'yes' : 'no'
              )
            }
            error={formErrors.is_filterable}
          />
        </FieldGroup>

        {initial?.values && initial.values.length > 0 && (
          <FieldGroup title="Allowed values">
            <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
              {initial.values.map((value) => (
                <span
                  key={value.id}
                  className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-alt px-2 py-0.5 text-xs text-text"
                >
                  {value.label ?? value.value}
                </span>
              ))}
            </div>
          </FieldGroup>
        )}
      </form>
    </Drawer>
  );
}
