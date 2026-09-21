import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { customerApi, customerGroupApi, priceListApi } from '@/api/services';
import type { Customer, CustomerType } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { decimalInputValue } from '@/utils/format';

interface CustomerFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Customer | null;
}

interface CustomerFormValues {
  customer_code: string;
  name: string;
  type: CustomerType | '';
  phone: string;
  email: string;
  address: string;
  city: string;
  province: string;
  country: string;
  postal_code: string;
  tax_number: string;
  credit_limit: string;
  payment_terms: string;
  birthday: string;
  is_active: 'yes' | 'no';
  customer_group_id: number | '';
  price_list_id: number | '';
  notes: string;
}

const NEW = 'new';
const CLOSED = 'closed';

const customerTypeOptions: { label: string; value: CustomerType }[] = (
  ['individual', 'company'] as const
).map((value) => ({
  value,
  label: value === 'individual' ? 'Individual' : 'Company',
}));

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

export function CustomerFormDrawer({
  open,
  onClose,
  initial,
}: CustomerFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: groupData } = useQuery({
    queryKey: ['customer-groups', 'drawer-options', companyId],
    queryFn: () =>
      customerGroupApi.list({ company_id: companyId ?? undefined, per_page: 200 }),
    enabled: open,
  });
  const groupOptions = (groupData?.data ?? []).map((group) => ({
    label: group.name,
    value: group.id,
  }));

  const { data: priceListData } = useQuery({
    queryKey: ['price-lists', 'drawer-options', companyId],
    queryFn: () =>
      priceListApi.list({ company_id: companyId ?? undefined, per_page: 200 }),
    enabled: open,
  });
  const priceListOptions = (priceListData?.data ?? [])
    .filter((priceList) => priceList.is_active)
    .map((priceList) => ({
      label: priceList.name,
      value: priceList.id,
    }));

  const [form, setForm] = useState<CustomerFormValues>({
    customer_code: '',
    name: '',
    type: 'individual',
    phone: '',
    email: '',
    address: '',
    city: '',
    province: '',
    country: 'Indonesia',
    postal_code: '',
    tax_number: '',
    credit_limit: '',
    payment_terms: '',
    birthday: '',
    is_active: 'yes',
    customer_group_id: '',
    price_list_id: '',
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
        customer_code: initial.customer_code ?? '',
        name: initial.name ?? '',
        type: initial.type ?? 'individual',
        phone: initial.phone ?? '',
        email: initial.email ?? '',
        address: initial.address ?? '',
        city: initial.city ?? '',
        province: initial.province ?? '',
        country: initial.country ?? '',
        postal_code: initial.postal_code ?? '',
        tax_number: initial.tax_number ?? '',
        credit_limit: decimalInputValue(initial.credit_limit),
        payment_terms: initial.payment_terms ?? '',
        birthday: initial.birthday ?? '',
        is_active: initial.is_active ? 'yes' : 'no',
        customer_group_id: initial.customer_group_id ?? '',
        price_list_id: initial.price_list_id ?? '',
        notes: initial.notes ?? '',
      });
    } else {
      setForm({
        customer_code: '',
        name: '',
        type: 'individual',
        phone: '',
        email: '',
        address: '',
        city: '',
        province: '',
        country: 'Indonesia',
        postal_code: '',
        tax_number: '',
        credit_limit: '',
        payment_terms: '',
        birthday: '',
        is_active: 'yes',
        customer_group_id: '',
        price_list_id: '',
        notes: '',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: CustomerFormValues) => {
      const payload = {
        customer_code: values.customer_code,
        name: values.name,
        type: values.type || null,
        phone: emptyToNull(values.phone),
        email: emptyToNull(values.email),
        address: emptyToNull(values.address),
        city: emptyToNull(values.city),
        province: emptyToNull(values.province),
        country: values.country,
        postal_code: emptyToNull(values.postal_code),
        tax_number: emptyToNull(values.tax_number),
        credit_limit: emptyToNull(values.credit_limit),
        payment_terms: emptyToNull(values.payment_terms),
        birthday: emptyToNull(values.birthday),
        is_active: values.is_active === 'yes',
        customer_group_id: values.customer_group_id === '' ? null : values.customer_group_id,
        price_list_id: values.price_list_id === '' ? null : values.price_list_id,
        notes: emptyToNull(values.notes),
      };

      return isEditing
        ? customerApi.update(id, payload)
        : customerApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Customer updated' : 'Customer created',
        variant: 'success',
      });
      invalidate(listQueryKeys.customers);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update customer' : 'Failed to create customer',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof CustomerFormValues>(
    field: K,
    value: CustomerFormValues[K]
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
      title={isEditing ? 'Edit customer' : 'New customer'}
      description={
        isEditing
          ? 'Update the customer profile and trading terms.'
          : 'Register a customer for sales and loyalty tracking.'
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
            form="customer-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create customer'}
          </Button>
        </>
      }
    >
      <form
        id="customer-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Details">
          <Input
            label="Customer code"
            name="customer_code"
            value={form.customer_code}
            onChange={(event) => setField('customer_code', event.target.value)}
            error={formErrors.customer_code}
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
            label="Type"
            name="type"
            options={customerTypeOptions}
            value={form.type}
            onChange={(event) =>
              setField('type', event.target.value as CustomerType | '')
            }
            error={formErrors.type}
          />
          <Select
            label="Customer group"
            name="customer_group_id"
            options={groupOptions}
            placeholder="No group"
            value={form.customer_group_id}
            onChange={(event) =>
              setField(
                'customer_group_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.customer_group_id}
          />
          <Select
            label="Price list"
            name="price_list_id"
            options={priceListOptions}
            placeholder="Default pricing"
            value={form.price_list_id}
            onChange={(event) =>
              setField(
                'price_list_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.price_list_id}
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
            label="Birthday"
            name="birthday"
            type="date"
            value={form.birthday}
            onChange={(event) => setField('birthday', event.target.value)}
            error={formErrors.birthday}
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
            label="Tax number"
            name="tax_number"
            value={form.tax_number}
            onChange={(event) => setField('tax_number', event.target.value)}
            error={formErrors.tax_number}
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
          <Input
            label="Payment terms"
            name="payment_terms"
            value={form.payment_terms}
            onChange={(event) => setField('payment_terms', event.target.value)}
            error={formErrors.payment_terms}
            placeholder="e.g. Net 30"
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
