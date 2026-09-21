import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { branchApi, companyApi, warehouseApi } from '@/api/services';
import type { Warehouse, WarehouseType } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { labelFor } from '@/utils/format';

interface WarehouseFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Warehouse | null;
}

interface WarehouseFormValues {
  company_id: number | '';
  branch_id: number | '';
  code: string;
  name: string;
  type: WarehouseType | '';
  description: string;
  status: 'active' | 'inactive';
}

const warehouseTypeOptions: { label: string; value: WarehouseType }[] = (
  ['main', 'outlet', 'production', 'transit'] as const
).map((value) => ({ value, label: labelFor.warehouseType(value) }));

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
];

const NEW = 'new';
const CLOSED = 'closed';

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

export function WarehouseFormDrawer({
  open,
  onClose,
  initial,
}: WarehouseFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const scopeCompanyId = useAuthStore((state) => state.scope.companyId);
  const companies = useAuthStore((state) => state.user?.companies) ?? [];
  const companyName = companies.find((c) => c.id === initial?.company_id)?.name;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: companyOptionsData } = useQuery({
    queryKey: ['companies', 'drawer-options'],
    queryFn: () => companyApi.list({ per_page: 100 }),
    enabled: open && !isEditing,
  });
  const companyOptions = (companyOptionsData?.data ?? []).map((company) => ({
    label: company.name,
    value: company.id,
  }));

  const [form, setForm] = useState<WarehouseFormValues>(() => ({
    company_id: scopeCompanyId ?? '',
    branch_id: '',
    code: '',
    name: '',
    type: '',
    description: '',
    status: 'active',
  }));
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const selectedCompanyId =
    form.company_id === '' ? null : form.company_id;

  // Branch options are scoped to the selected company.
  const { data: branchOptionsData } = useQuery({
    queryKey: ['branches', 'drawer-options', selectedCompanyId],
    queryFn: () =>
      branchApi.list({
        company_id: selectedCompanyId ?? undefined,
        per_page: 100,
      }),
    enabled: open && selectedCompanyId !== null,
  });
  const branchOptions = (branchOptionsData?.data ?? []).map((branch) => ({
    label: branch.name,
    value: branch.id,
  }));

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);

    if (initial) {
      setForm({
        company_id: initial.company_id,
        branch_id: initial.branch_id ?? '',
        code: initial.code ?? '',
        name: initial.name ?? '',
        type: initial.type ?? '',
        description: initial.description ?? '',
        status: initial.status === 'inactive' ? 'inactive' : 'active',
      });
    } else {
      setForm((prev) => ({
        ...prev,
        company_id: scopeCompanyId ?? '',
        branch_id: '',
        code: '',
        name: '',
        type: '',
        description: '',
        status: 'active',
      }));
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: WarehouseFormValues) => {
      const base = {
        branch_id: values.branch_id === '' ? null : values.branch_id,
        code: values.code,
        name: values.name,
        type: values.type || null,
        description: emptyToNull(values.description),
        status: values.status,
      };

      // company_id is immutable once the warehouse exists.
      if (isEditing) {
        return warehouseApi.update(id, base);
      }

      return warehouseApi.create({
        ...base,
        company_id: values.company_id === '' ? undefined : values.company_id,
      });
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Warehouse updated' : 'Warehouse created',
        variant: 'success',
      });
      invalidate(listQueryKeys.warehouses);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update warehouse' : 'Failed to create warehouse',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof WarehouseFormValues>(
    field: K,
    value: WarehouseFormValues[K]
  ) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setFormErrors((prev) => {
      if (!prev[field]) {
        return prev;
      }
      return { ...prev, [field]: '' };
    });
  };

  const handleCompanyChange = (value: string) => {
    // Branches belong to a company, so the selection must be cleared.
    setForm((prev) => ({
      ...prev,
      company_id: value === '' ? '' : Number(value),
      branch_id: '',
    }));
    setFormErrors((prev) => ({ ...prev, company_id: '', branch_id: '' }));
  };

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    mutation.mutate(form);
  };

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? 'Edit warehouse' : 'New warehouse'}
      description={
        isEditing
          ? 'Update the warehouse details. The company cannot be changed.'
          : 'Add a storage location belonging to a company.'
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
            form="warehouse-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create warehouse'}
          </Button>
        </>
      }
    >
      <form
        id="warehouse-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Details">
          {isEditing ? (
            <Input
              label="Company"
              value={companyName ?? '-'}
              disabled
              wrapperClassName="sm:col-span-2"
            />
          ) : (
            <Select
              label="Company"
              name="company_id"
              options={companyOptions}
              placeholder="Select company"
              value={form.company_id}
              onChange={(event) => handleCompanyChange(event.target.value)}
              error={formErrors.company_id}
              wrapperClassName="sm:col-span-2"
              required
            />
          )}
          <Select
            label="Branch"
            name="branch_id"
            options={branchOptions}
            placeholder={selectedCompanyId === null ? 'Select a company first' : 'No branch'}
            value={form.branch_id}
            onChange={(event) =>
              setField(
                'branch_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.branch_id}
            disabled={selectedCompanyId === null}
            wrapperClassName="sm:col-span-2"
          />
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
            wrapperClassName="sm:col-span-2"
            required
          />
          <Select
            label="Type"
            name="type"
            options={warehouseTypeOptions}
            placeholder="Select type"
            value={form.type}
            onChange={(event) =>
              setField('type', event.target.value as WarehouseType | '')
            }
            error={formErrors.type}
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
        </FieldGroup>

        <FieldGroup title="Additional">
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
