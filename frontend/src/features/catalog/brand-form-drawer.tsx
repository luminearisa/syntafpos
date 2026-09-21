import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { brandApi } from '@/api/services';
import type { Brand } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';

interface BrandFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Brand | null;
}

interface BrandFormValues {
  code: string;
  name: string;
  description: string;
  status: 'active' | 'inactive';
}

const NEW = 'new';
const CLOSED = 'closed';

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
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

export function BrandFormDrawer({ open, onClose, initial }: BrandFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<BrandFormValues>({
    code: '',
    name: '',
    description: '',
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
        status: initial.status === 'inactive' ? 'inactive' : 'active',
      });
    } else {
      setForm({
        code: '',
        name: '',
        description: '',
        status: 'active',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: BrandFormValues) => {
      const payload = {
        code: values.code,
        name: values.name,
        description: emptyToNull(values.description),
        status: values.status,
      };

      return isEditing ? brandApi.update(id, payload) : brandApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Brand updated' : 'Brand created',
        variant: 'success',
      });
      invalidate(listQueryKeys.brands);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update brand' : 'Failed to create brand',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof BrandFormValues>(
    field: K,
    value: BrandFormValues[K]
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
      title={isEditing ? 'Edit brand' : 'New brand'}
      description={
        isEditing
          ? 'Update the brand details.'
          : 'Register a manufacturer or brand for your catalogue.'
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
            form="brand-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create brand'}
          </Button>
        </>
      }
    >
      <form id="brand-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FieldGroup title="Details">
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique within the company"
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
      </form>
    </Drawer>
  );
}
