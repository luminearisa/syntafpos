import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { branchApi, companyApi } from '@/api/services';
import type { Branch, BranchType } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';
import { labelFor } from '@/utils/format';

interface BranchFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Branch | null;
}

interface BranchFormValues {
  company_id: number | '';
  code: string;
  name: string;
  type: BranchType | '';
  phone: string;
  email: string;
  address: string;
  city: string;
  province: string;
  country: string;
  postal_code: string;
  timezone: string;
  status: 'active' | 'inactive';
}

const NEW = 'new';
const CLOSED = 'closed';

const branchTypeOptions: { label: string; value: BranchType }[] = (
  ['head_office', 'outlet', 'warehouse', 'other'] as const
).map((value) => ({ value, label: labelFor.branchType(value) }));

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

export function BranchFormDrawer({
  open,
  onClose,
  initial,
}: BranchFormDrawerProps) {
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

  const [form, setForm] = useState<BranchFormValues>(() => ({
    company_id: scopeCompanyId ?? '',
    code: '',
    name: '',
    type: '',
    phone: '',
    email: '',
    address: '',
    city: '',
    province: '',
    country: 'Indonesia',
    postal_code: '',
    timezone: 'Asia/Jakarta',
    status: 'active',
  }));
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    if (initial) {
      setForm({
        company_id: initial.company_id,
        code: initial.code ?? '',
        name: initial.name ?? '',
        type: initial.type ?? '',
        phone: initial.phone ?? '',
        email: initial.email ?? '',
        address: initial.address ?? '',
        city: initial.city ?? '',
        province: initial.province ?? '',
        country: initial.country ?? '',
        postal_code: initial.postal_code ?? '',
        timezone: initial.timezone ?? '',
        status: initial.status === 'inactive' ? 'inactive' : 'active',
      });
    } else {
      setForm({
        company_id: scopeCompanyId ?? '',
        code: '',
        name: '',
        type: '',
        phone: '',
        email: '',
        address: '',
        city: '',
        province: '',
        country: 'Indonesia',
        postal_code: '',
        timezone: 'Asia/Jakarta',
        status: 'active',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: BranchFormValues) => {
      const base = {
        code: values.code,
        name: values.name,
        type: values.type || null,
        phone: emptyToNull(values.phone),
        email: emptyToNull(values.email),
        address: emptyToNull(values.address),
        city: emptyToNull(values.city),
        province: emptyToNull(values.province),
        country: values.country,
        postal_code: emptyToNull(values.postal_code),
        timezone: values.timezone,
        status: values.status,
      };

      // company_id is immutable once the branch exists.
      if (isEditing) {
        return branchApi.update(id, base);
      }

      return branchApi.create({
        ...base,
        company_id: values.company_id === '' ? undefined : values.company_id,
      });
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Branch updated' : 'Branch created',
        variant: 'success',
      });
      invalidate(listQueryKeys.branches);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update branch' : 'Failed to create branch',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof BranchFormValues>(
    field: K,
    value: BranchFormValues[K]
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
      title={isEditing ? 'Edit branch' : 'New branch'}
      description={
        isEditing
          ? 'Update the branch details. The company cannot be changed.'
          : 'Add a location belonging to a company.'
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
            form="branch-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create branch'}
          </Button>
        </>
      }
    >
      <form id="branch-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
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
              onChange={(event) =>
                setField(
                  'company_id',
                  event.target.value === '' ? '' : Number(event.target.value)
                )
              }
              error={formErrors.company_id}
              wrapperClassName="sm:col-span-2"
              required
            />
          )}
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
            options={branchTypeOptions}
            placeholder="Select type"
            value={form.type}
            onChange={(event) =>
              setField('type', event.target.value as BranchType | '')
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

        <FieldGroup title="Contact">
          <Input
            label="Phone"
            name="phone"
            value={form.phone}
            onChange={(event) => setField('phone', event.target.value)}
            error={formErrors.phone}
          />
          <Input
            label="Email"
            name="email"
            type="email"
            value={form.email}
            onChange={(event) => setField('email', event.target.value)}
            error={formErrors.email}
          />
          <Input
            label="Address"
            name="address"
            value={form.address}
            onChange={(event) => setField('address', event.target.value)}
            error={formErrors.address}
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
          <Input
            label="City"
            name="city"
            value={form.city}
            onChange={(event) => setField('city', event.target.value)}
            error={formErrors.city}
          />
          <Input
            label="Province"
            name="province"
            value={form.province}
            onChange={(event) => setField('province', event.target.value)}
            error={formErrors.province}
          />
          <Input
            label="Country"
            name="country"
            value={form.country}
            onChange={(event) => setField('country', event.target.value)}
            error={formErrors.country}
          />
          <Input
            label="Postal code"
            name="postal_code"
            value={form.postal_code}
            onChange={(event) => setField('postal_code', event.target.value)}
            error={formErrors.postal_code}
          />
          <Input
            label="Timezone"
            name="timezone"
            value={form.timezone}
            onChange={(event) => setField('timezone', event.target.value)}
            error={formErrors.timezone}
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
