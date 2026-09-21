import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { paymentMethodApi } from '@/api/services';
import type { PaymentChannel, PaymentMethod } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useToast } from '@/components/ui/toast';
import { apiErrorMessage, apiFieldErrors } from '@/utils/api-error';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';
import { AVAILABLE_METHODS_KEY } from './use-payment-methods';

interface PaymentMethodFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: PaymentMethod | null;
}

interface PaymentMethodFormValues {
  code: string;
  name: string;
  channel: PaymentChannel | '';
  provider: string;
  icon: string;
  description: string;
  requires_reference: 'yes' | 'no';
  is_default: 'yes' | 'no';
  is_active: 'yes' | 'no';
  sort_order: string;
  settings: string;
}

/**
 * The nine channels, listed because behaviour follows them.
 *
 * A shop picks one and this is where the form explains what it is picking: only
 * `cash` says it can hand change back, and the hint under the select says so, so
 * nobody configures a "cash" method onto a card and then reconciles a short drawer.
 */
const channelOptions: { label: string; value: PaymentChannel; hint: string }[] = [
  { label: 'Cash', value: 'cash', hint: 'Money into the drawer; over-paying produces change.' },
  { label: 'Bank transfer', value: 'bank_transfer', hint: 'Confirm against a reference or slip.' },
  { label: 'Debit card', value: 'debit', hint: 'Settled by the terminal at the counter.' },
  { label: 'Credit card', value: 'credit_card', hint: 'Settled by the terminal at the counter.' },
  { label: 'QRIS', value: 'qris', hint: 'Scanned; a gateway can capture it later (3.8).' },
  { label: 'E-wallet', value: 'e_wallet', hint: 'GoPay, OVO, Dana — usually with a reference.' },
  { label: 'Virtual account', value: 'virtual_account', hint: 'Per-invoice bank number.' },
  { label: 'Customer credit', value: 'customer_credit', hint: 'Charges a customer account; needs a customer on the sale.' },
  { label: 'Other', value: 'other', hint: 'Vouchers, aid payments, anything else this shop takes.' },
];

const yesNoOptions = [
  { label: 'Yes', value: 'yes' },
  { label: 'No', value: 'no' },
];

const BLANK: PaymentMethodFormValues = {
  code: '',
  name: '',
  channel: '',
  provider: '',
  icon: '',
  description: '',
  requires_reference: 'no',
  is_default: 'no',
  is_active: 'yes',
  sort_order: '0',
  settings: '',
};

const NEW = 'new';
const CLOSED = 'closed';

function formFrom(method: PaymentMethod): PaymentMethodFormValues {
  return {
    code: method.code ?? '',
    name: method.name ?? '',
    channel: method.channel,
    provider: method.provider ?? '',
    icon: method.icon ?? '',
    description: method.description ?? '',
    requires_reference: method.requires_reference ? 'yes' : 'no',
    is_default: method.is_default ? 'yes' : 'no',
    is_active: method.is_active ? 'yes' : 'no',
    sort_order: String(method.sort_order ?? 0),
    settings: method.settings ? JSON.stringify(method.settings, null, 2) : '',
  };
}

export function PaymentMethodFormDrawer({
  open,
  onClose,
  initial,
}: PaymentMethodFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const { toast } = useToast();
  const client = useQueryClient();

  const [form, setForm] = useState<PaymentMethodFormValues>(BLANK);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [settingsError, setSettingsError] = useState<string | null>(null);

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm(initial ? formFrom(initial) : BLANK);
    setFormErrors({});
    setSettingsError(null);
  }

  const invalidate = () => {
    client.invalidateQueries({ queryKey: listQueryKeys.paymentMethods });
    // The till reads the same rows through `available`; a rename or a
    // deactivation should be on the next customer's payment screen, not the
    // next browser reload.
    client.invalidateQueries({ queryKey: AVAILABLE_METHODS_KEY });
  };

  const mutation = useMutation({
    mutationFn: (values: PaymentMethodFormValues) => {
      const payload: Partial<PaymentMethod> = {
        code: values.code.trim(),
        name: values.name.trim(),
        channel: (values.channel || undefined) as PaymentChannel | undefined,
        provider: values.provider.trim() === '' ? null : values.provider.trim(),
        icon: values.icon.trim() === '' ? null : values.icon.trim(),
        description: values.description.trim() === '' ? null : values.description.trim(),
        requires_reference: values.requires_reference === 'yes',
        is_default: values.is_default === 'yes',
        is_active: values.is_active === 'yes',
        sort_order: Number(values.sort_order || 0),
      };

      payload.settings = settingsFrom(values.settings);

      return isEditing ? paymentMethodApi.update(id, payload) : paymentMethodApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Payment method updated' : 'Payment method created',
        variant: 'success',
      });
      invalidate();
      onClose();
    },
    onError: (error) => {
      const fields = apiFieldErrors(error);

      setFormErrors(fields);
      toast({
        title: isEditing ? 'Failed to update payment method' : 'Failed to create payment method',
        message: apiErrorMessage(error, 'Check the form and try again.'),
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof PaymentMethodFormValues>(
    field: K,
    value: PaymentMethodFormValues[K]
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

    // Half-typed JSON never reaches the server: the box explains itself where the
    // cashier is typing, and the drawer does not close on a doomed save.
    const problem = settingsProblem(form.settings);

    if (problem) {
      setSettingsError(problem);

      return;
    }

    setSettingsError(null);
    mutation.mutate(form);
  };

  const channelHint = channelOptions.find((option) => option.value === form.channel)?.hint;

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={isEditing ? `Edit ${initial?.name}` : 'New payment method'}
      description={
        isEditing
          ? 'Rename, reorder or switch off the way this shop is paid. The channel is locked once payments exist.'
          : 'Add a way this shop can be paid. What the money physically is — its channel — decides the behaviour at the till.'
      }
      width="max-w-lg"
      footer={
        <>
          <Button variant="secondary" size="sm" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            type="submit"
            form="payment-method-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create method'}
          </Button>
        </>
      }
    >
      <form id="payment-method-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FieldGroup title="What this method is">
          <Input
            label="Code"
            name="code"
            value={form.code}
            onChange={(event) => setField('code', event.target.value)}
            error={formErrors.code}
            hint="Unique within the company, e.g. QRIS-BCA"
            required
          />
          <Input
            label="Name at the till"
            name="name"
            value={form.name}
            onChange={(event) => setField('name', event.target.value)}
            error={formErrors.name}
            hint="The button the cashier reads, e.g. QRIS BCA"
            required
          />
          <Select
            label="Channel"
            name="channel"
            options={channelOptions.map(({ label, value }) => ({ label, value }))}
            placeholder="What kind of money is this?"
            value={form.channel}
            onChange={(event) => setField('channel', event.target.value as PaymentChannel)}
            error={formErrors.channel}
            disabled={isEditing && (initial?.payments_count ?? 0) > 0}
          />
          {channelHint && <p className="text-xs text-text-muted">{channelHint}</p>}
          {isEditing && (initial?.payments_count ?? 0) > 0 && (
            <p className="text-xs text-warning">
              {initial?.payments_count} payment(s) have used this method, so its channel is locked —
              a QRIS button reclassified as cash would leave its past tenders describing change
              from a drawer that never opened.
            </p>
          )}
        </FieldGroup>

        <FieldGroup title="How the till uses it">
          <Select
            label="Requires a reference"
            name="requires_reference"
            options={yesNoOptions}
            value={form.requires_reference}
            onChange={(event) =>
              setField('requires_reference', event.target.value === 'yes' ? 'yes' : 'no')
            }
            error={formErrors.requires_reference}
          />
          <Input
            label="Sort order"
            name="sort_order"
            inputMode="numeric"
            value={form.sort_order}
            onChange={(event) => setField('sort_order', event.target.value)}
            error={formErrors.sort_order}
            hint="Till buttons are listed by this, smallest first"
          />
          <Select
            label="Default method"
            name="is_default"
            options={yesNoOptions}
            value={form.is_default}
            onChange={(event) => setField('is_default', event.target.value === 'yes' ? 'yes' : 'no')}
            error={formErrors.is_default}
          />
          <Select
            label="Active"
            name="is_active"
            options={yesNoOptions}
            value={form.is_active}
            onChange={(event) => setField('is_active', event.target.value === 'yes' ? 'yes' : 'no')}
            error={formErrors.is_active}
          />
        </FieldGroup>

        <FieldGroup title="Optional">
          <Input
            label="Provider"
            name="provider"
            value={form.provider}
            onChange={(event) => setField('provider', event.target.value)}
            error={formErrors.provider}
            hint="Empty records the tender at the counter. A gateway key (e.g. midtrans) is set in Subphase 3.8."
          />
          <Input
            label="Icon"
            name="icon"
            value={form.icon}
            onChange={(event) => setField('icon', event.target.value)}
            error={formErrors.icon}
            hint="ionicons name, e.g. qr-code-outline"
          />
          <Textarea
            label="Description"
            name="description"
            rows={2}
            value={form.description}
            onChange={(event) => setField('description', event.target.value)}
            error={formErrors.description}
            placeholder="Shown to whoever configures this, never on a receipt"
          />
          <Textarea
            label="Settings (JSON)"
            name="settings"
            rows={3}
            value={form.settings}
            onChange={(event) => {
              setField('settings', event.target.value);
              setSettingsError(null);
            }}
            error={formErrors.settings ?? settingsError ?? undefined}
            placeholder='{"terminal": "B-221"}'
          />
          <p className="text-xs text-text-muted">
            Channel detail that is not behaviour — an account number to print, a terminal id.
          </p>
        </FieldGroup>
      </form>
    </Drawer>
  );
}

/**
 * The settings box, and what is wrong with it if anything is.
 *
 * A JSON textarea is the friendliest way to expose per-channel settings without a
 * bespoke form per channel — but half-typed JSON must not reach the server, so the
 * drawer checks it before saving and says what it needs.
 */
function settingsProblem(text: string): string | null {
  const trimmed = text.trim();

  if (trimmed === '') {
    return null;
  }

  let parsed: unknown;

  try {
    parsed = JSON.parse(trimmed);
  } catch {
    return 'Settings is not valid JSON yet.';
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return 'Settings must be a JSON object, e.g. {"terminal": "B-221"}.';
  }

  return null;
}

function settingsFrom(text: string): Record<string, unknown> {
  const trimmed = text.trim();

  if (trimmed === '') {
    return {};
  }

  return JSON.parse(trimmed) as Record<string, unknown>;
}
