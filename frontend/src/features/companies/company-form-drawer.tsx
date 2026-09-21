import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { companyApi } from '@/api/services';
import type { Company } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';

interface CompanyFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Company | null;
}

interface CompanyFormValues {
  name: string;
  legal_name: string;
  code: string;
  email: string;
  phone: string;
  address: string;
  city: string;
  province: string;
  country: string;
  postal_code: string;
  tax_number: string;
  currency: string;
  timezone: string;
  fiscal_year_start: string;
  status: 'active' | 'inactive';
}

const NEW = 'new';
const CLOSED = 'closed';

const defaultValues: CompanyFormValues = {
  name: '',
  legal_name: '',
  code: '',
  email: '',
  phone: '',
  address: '',
  city: '',
  province: '',
  country: 'Indonesia',
  postal_code: '',
  tax_number: '',
  currency: 'IDR',
  timezone: 'Asia/Jakarta',
  fiscal_year_start: '',
  status: 'active',
};

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
];

function toFormValues(company: Company): CompanyFormValues {
  return {
    name: company.name ?? '',
    legal_name: company.legal_name ?? '',
    code: company.code ?? '',
    email: company.email ?? '',
    phone: company.phone ?? '',
    address: company.address ?? '',
    city: company.city ?? '',
    province: company.province ?? '',
    country: company.country ?? '',
    postal_code: company.postal_code ?? '',
    tax_number: company.tax_number ?? '',
    currency: company.currency ?? 'IDR',
    timezone: company.timezone ?? 'Asia/Jakarta',
    fiscal_year_start: company.fiscal_year_start ?? '',
    status: company.status === 'inactive' ? 'inactive' : 'active',
  };
}

function emptyToNull(value: string): string | null {
  return value.trim() === '' ? null : value;
}

interface ApiErrors {
  fieldErrors: Record<string, string>;
  message: string;
}

function extractApiErrors(error: unknown): ApiErrors {
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

export function CompanyFormDrawer({
  open,
  onClose,
  initial,
}: CompanyFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<CompanyFormValues>(defaultValues);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm(initial ? toFormValues(initial) : defaultValues);
    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: CompanyFormValues) => {
      const payload: Partial<Company> = {
        name: values.name,
        code: values.code,
        legal_name: emptyToNull(values.legal_name),
        email: emptyToNull(values.email),
        phone: emptyToNull(values.phone),
        address: emptyToNull(values.address),
        city: emptyToNull(values.city),
        province: emptyToNull(values.province),
        country: values.country,
        postal_code: emptyToNull(values.postal_code),
        tax_number: emptyToNull(values.tax_number),
        currency: values.currency,
        timezone: values.timezone,
        fiscal_year_start: emptyToNull(values.fiscal_year_start),
        status: values.status,
      };

      return isEditing
        ? companyApi.update(id, payload)
        : companyApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Company updated' : 'Company created',
        variant: 'success',
      });
      invalidate(listQueryKeys.companies);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update company' : 'Failed to create company',
        message,
        variant: 'error',
      });
    },
  });

  const setField = (field: keyof CompanyFormValues, value: string) => {
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

  const description = isEditing
    ? 'Update the company profile and preferences.'
    : 'Create a new business entity.';

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? 'Edit company' : 'New company'}
      description={description}
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
            form="company-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create company'}
          </Button>
        </>
      }
    >
      <form id="company-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FieldGroup title="Identity">
          <Input
            label="Name"
            name="name"
            value={form.name}
            onChange={(event) => setField('name', event.target.value)}
            error={formErrors.name}
            wrapperClassName="sm:col-span-2"
            required
          />
          <Input
            label="Legal name"
            name="legal_name"
            value={form.legal_name}
            onChange={(event) => setField('legal_name', event.target.value)}
            error={formErrors.legal_name}
            wrapperClassName="sm:col-span-2"
          />
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique shorthand, e.g. ACME"
            required
          />
        </FieldGroup>

        <FieldGroup title="Contact">
          <Input
            label="Email"
            name="email"
            type="email"
            value={form.email}
            onChange={(event) => setField('email', event.target.value)}
            error={formErrors.email}
          />
          <Input
            label="Phone"
            name="phone"
            value={form.phone}
            onChange={(event) => setField('phone', event.target.value)}
            error={formErrors.phone}
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
        </FieldGroup>

        <FieldGroup title="Financial / Locale">
          <Input
            label="Tax number"
            name="tax_number"
            value={form.tax_number}
            onChange={(event) => setField('tax_number', event.target.value)}
            error={formErrors.tax_number}
          />
          <Input
            label="Currency"
            name="currency"
            value={form.currency}
            onChange={(event) => setField('currency', event.target.value)}
            error={formErrors.currency}
            hint="Max 8 characters"
          />
          <Input
            label="Timezone"
            name="timezone"
            value={form.timezone}
            onChange={(event) => setField('timezone', event.target.value)}
            error={formErrors.timezone}
          />
          <Input
            label="Fiscal year start"
            name="fiscal_year_start"
            type="date"
            value={form.fiscal_year_start}
            onChange={(event) => setField('fiscal_year_start', event.target.value)}
            error={formErrors.fiscal_year_start}
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
      </form>
    </Drawer>
  );
}
