import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { supplierApi } from '@/api/services';
import type { Supplier } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue } from '@/utils/format';

interface SupplierFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Supplier | null;
}

interface SupplierFormValues {
  supplier_code: string;
  name: string;
  company_name: string;
  contact_person: string;
  phone: string;
  email: string;
  address: string;
  city: string;
  province: string;
  country: string;
  postal_code: string;
  tax_number: string;
  payment_terms: string;
  credit_limit: string;
  bank_name: string;
  bank_account: string;
  bank_account_name: string;
  status: 'active' | 'inactive';
  notes: string;
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

export function SupplierFormDrawer({
  open,
  onClose,
  initial,
}: SupplierFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const [form, setForm] = useState<SupplierFormValues>({
    supplier_code: '',
    name: '',
    company_name: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    city: '',
    province: '',
    country: 'Indonesia',
    postal_code: '',
    tax_number: '',
    payment_terms: '',
    credit_limit: '',
    bank_name: '',
    bank_account: '',
    bank_account_name: '',
    status: 'active',
    notes: '',
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
        supplier_code: initial.supplier_code ?? '',
        name: initial.name ?? '',
        company_name: initial.company_name ?? '',
        contact_person: initial.contact_person ?? '',
        phone: initial.phone ?? '',
        email: initial.email ?? '',
        address: initial.address ?? '',
        city: initial.city ?? '',
        province: initial.province ?? '',
        country: initial.country ?? '',
        postal_code: initial.postal_code ?? '',
        tax_number: initial.tax_number ?? '',
        payment_terms: initial.payment_terms ?? '',
        credit_limit: decimalInputValue(initial.credit_limit),
        bank_name: initial.bank_name ?? '',
        bank_account: initial.bank_account ?? '',
        bank_account_name: initial.bank_account_name ?? '',
        status: initial.status === 'inactive' ? 'inactive' : 'active',
        notes: initial.notes ?? '',
      });
    } else {
      setForm({
        supplier_code: '',
        name: '',
        company_name: '',
        contact_person: '',
        phone: '',
        email: '',
        address: '',
        city: '',
        province: '',
        country: 'Indonesia',
        postal_code: '',
        tax_number: '',
        payment_terms: '',
        credit_limit: '',
        bank_name: '',
        bank_account: '',
        bank_account_name: '',
        status: 'active',
        notes: '',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: SupplierFormValues) => {
      const payload = {
        supplier_code: values.supplier_code,
        name: values.name,
        company_name: emptyToNull(values.company_name),
        contact_person: emptyToNull(values.contact_person),
        phone: emptyToNull(values.phone),
        email: emptyToNull(values.email),
        address: emptyToNull(values.address),
        city: emptyToNull(values.city),
        province: emptyToNull(values.province),
        country: values.country,
        postal_code: emptyToNull(values.postal_code),
        tax_number: emptyToNull(values.tax_number),
        payment_terms: emptyToNull(values.payment_terms),
        credit_limit: emptyToNull(values.credit_limit),
        bank_name: emptyToNull(values.bank_name),
        bank_account: emptyToNull(values.bank_account),
        bank_account_name: emptyToNull(values.bank_account_name),
        status: values.status,
        notes: emptyToNull(values.notes),
      };

      return isEditing
        ? supplierApi.update(id, payload)
        : supplierApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Supplier updated' : 'Supplier created',
        variant: 'success',
      });
      invalidate(listQueryKeys.suppliers);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update supplier' : 'Failed to create supplier',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof SupplierFormValues>(
    field: K,
    value: SupplierFormValues[K]
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
      title={isEditing ? 'Edit supplier' : 'New supplier'}
      description={
        isEditing
          ? 'Update the supplier profile and trading terms.'
          : 'Register a supplier for purchasing and goods receipt.'
      }
      width="max-w-2xl"
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
            form="supplier-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create supplier'}
          </Button>
        </>
      }
    >
      <form
        id="supplier-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Details">
          <Input
            label="Supplier code"
            name="supplier_code"
            value={form.supplier_code}
            onChange={(event) => setField('supplier_code', event.target.value)}
            error={formErrors.supplier_code}
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
          <Input
            label="Company name"
            name="company_name"
            value={form.company_name}
            onChange={(event) => setField('company_name', event.target.value)}
            error={formErrors.company_name}
            hint="Legal entity name, if different"
          />
          <Input
            label="Contact person"
            name="contact_person"
            value={form.contact_person}
            onChange={(event) => setField('contact_person', event.target.value)}
            error={formErrors.contact_person}
          />
          <Input
            label="Tax number"
            name="tax_number"
            value={form.tax_number}
            onChange={(event) => setField('tax_number', event.target.value)}
            error={formErrors.tax_number}
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
        </FieldGroup>

        <FieldGroup title="Trading terms">
          <Input
            label="Payment terms"
            name="payment_terms"
            value={form.payment_terms}
            onChange={(event) => setField('payment_terms', event.target.value)}
            error={formErrors.payment_terms}
            placeholder="e.g. Net 30"
          />
          <Input
            label="Credit limit"
            name="credit_limit"
            inputMode="decimal"
            value={form.credit_limit}
            onChange={(event) => setField('credit_limit', event.target.value)}
            error={formErrors.credit_limit}
            hint="Leave empty for unlimited credit"
          />
        </FieldGroup>

        <FieldGroup title="Bank account">
          <Input
            label="Bank name"
            name="bank_name"
            value={form.bank_name}
            onChange={(event) => setField('bank_name', event.target.value)}
            error={formErrors.bank_name}
          />
          <Input
            label="Account number"
            name="bank_account"
            value={form.bank_account}
            onChange={(event) => setField('bank_account', event.target.value)}
            error={formErrors.bank_account}
          />
          <Input
            label="Account holder"
            name="bank_account_name"
            value={form.bank_account_name}
            onChange={(event) =>
              setField('bank_account_name', event.target.value)
            }
            error={formErrors.bank_account_name}
          />
          <Textarea
            label="Notes"
            name="notes"
            value={form.notes}
            onChange={(event) => setField('notes', event.target.value)}
            error={formErrors.notes}
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>
      </form>
    </Drawer>
  );
}
