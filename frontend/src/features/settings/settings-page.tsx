import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { settingsApi } from '@/api/services';
import { useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import {
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
} from '@/components/ui/state';
import { useToast } from '@/components/ui/toast';
import type { SettingsResponse } from '@/types';

type FieldKind = 'text' | 'number' | 'boolean' | 'select' | 'json';

interface KnownField {
  kind: FieldKind;
  label: string;
  options?: { label: string; value: string }[];
  step?: string;
}

const CONTROL_CLASSES =
  'h-9 w-full rounded-md border border-border bg-surface px-3 text-sm text-text focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:bg-surface-alt disabled:opacity-60';

const CHECKBOX_CLASSES =
  'h-4 w-4 rounded border-border text-primary focus:ring-primary';

// Explicit controls for keys whose intended input is not obvious from the
// stored value alone.
const KNOWN_FIELDS: Record<string, KnownField> = {
  'company.name': { kind: 'text', label: 'Business name' },
  'company.currency': { kind: 'text', label: 'Currency code' },
  'company.currency_symbol': { kind: 'text', label: 'Currency symbol' },
  'company.currency_decimals': { kind: 'number', label: 'Currency decimals' },
  'company.currency_thousand_separator': {
    kind: 'text',
    label: 'Thousand separator',
  },
  'company.currency_decimal_separator': {
    kind: 'text',
    label: 'Decimal separator',
  },
  'company.timezone': { kind: 'text', label: 'Timezone' },

  'pos.receipt_width': { kind: 'number', label: 'Receipt width (mm)' },
  'pos.auto_print': { kind: 'boolean', label: 'Auto print receipts' },
  'pos.allow_negative_stock': {
    kind: 'boolean',
    label: 'Allow negative stock sales',
  },

  'inventory.enabled': { kind: 'boolean', label: 'Inventory module enabled' },
  'inventory.valuation_method': {
    kind: 'select',
    label: 'Stock valuation method',
    options: [
      { label: 'FIFO', value: 'fifo' },
      { label: 'LIFO', value: 'lifo' },
      { label: 'Average', value: 'average' },
    ],
  },

  'accounting.enabled': {
    kind: 'boolean',
    label: 'Accounting module enabled',
  },
  'accounting.fiscal_year': {
    kind: 'select',
    label: 'Fiscal year',
    options: [
      { label: 'Monthly', value: 'monthly' },
      { label: 'Quarterly', value: 'quarterly' },
      { label: 'Yearly', value: 'yearly' },
    ],
  },
  'accounting.standard': {
    kind: 'select',
    label: 'Accounting standard',
    options: [
      { label: 'SAK EMKM', value: 'sak_emkm' },
      { label: 'PSAK', value: 'psak' },
      { label: 'IFRS', value: 'ifrs' },
    ],
  },

  'tax.enabled': { kind: 'boolean', label: 'Tax enabled' },
  'tax.default_rate': { kind: 'number', label: 'Default tax rate (%)', step: '0.01' },

  'payment.enabled': { kind: 'boolean', label: 'Payment module enabled' },
};

const GROUP_LABELS: Record<string, string> = {
  company: 'Company',
  pos: 'Point of Sale',
  inventory: 'Inventory',
  accounting: 'Accounting',
  tax: 'Tax',
  payment: 'Payment',
};

function labelFromKey(key: string): string {
  const segments = key.split('.');
  const name = segments.slice(1).join('.') || key;

  return name
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

function resolveField(key: string, value: unknown): KnownField {
  const known = KNOWN_FIELDS[key];

  if (known) {
    return known;
  }

  if (typeof value === 'boolean') {
    return { kind: 'boolean', label: labelFromKey(key) };
  }

  if (typeof value === 'number') {
    return { kind: 'number', label: labelFromKey(key) };
  }

  if (value !== null && typeof value === 'object') {
    return { kind: 'json', label: labelFromKey(key) };
  }

  return { kind: 'text', label: labelFromKey(key) };
}

function extractApiFailure(error: unknown): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const body = (error as { response?: { data?: Record<string, unknown> } })
      .response?.data;

    if (body) {
      const errors = (body['errors'] as Record<string, string[]>) ?? {};
      const values = Object.values(errors);

      if (values.length > 0 && values[0] && values[0].length > 0) {
        return values[0][0] ?? 'Failed to save settings';
      }

      if (typeof body['message'] === 'string') {
        return body['message'];
      }
    }
  }

  if (error instanceof Error) {
    return error.message;
  }

  return 'Failed to save settings';
}

export default function SettingsPage() {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const can = useAuthStore((state) => state.can);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const settingsQuery = useQuery({
    queryKey: listQueryKeys.settings,
    queryFn: () => settingsApi.index(),
    enabled: companyId !== null,
  });

  const response: SettingsResponse | undefined = settingsQuery.data?.data;
  const values = response?.values ?? {};

  const [draft, setDraft] = useState<Record<string, unknown>>({});

  // The draft mirrors the server values; it is re-seeded whenever the query
  // refreshes so the dirty diff resets after a save.
  useEffect(() => {
    setDraft(values);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settingsQuery.data]);

  const saveMutation = useMutation({
    mutationFn: (changed: Record<string, unknown>) =>
      settingsApi.update(changed),
    onSuccess: () => {
      toast({ title: 'Settings saved', variant: 'success' });
      invalidate(listQueryKeys.settings);
    },
    onError: (error) => {
      toast({ title: extractApiFailure(error), variant: 'error' });
    },
  });

  const updateField = (key: string, value: unknown) => {
    setDraft((current) => ({ ...current, [key]: value }));
  };

  const changedKeys = Object.keys(draft).filter(
    (key) => draft[key] !== values[key]
  );

  const handleSave = () => {
    if (changedKeys.length === 0) {
      return;
    }

    const changed: Record<string, unknown> = {};
    changedKeys.forEach((key) => {
      changed[key] = draft[key];
    });

    saveMutation.mutate(changed);
  };

  const renderControl = (key: string) => {
    const field = resolveField(key, values[key]);
    const value = draft[key];

    switch (field.kind) {
      case 'boolean':
        return (
          <label className="inline-flex cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              checked={!!value}
              onChange={(event) => updateField(key, event.target.checked)}
              className={CHECKBOX_CLASSES}
            />
            <span className="text-xs text-text-muted">
              {value ? 'On' : 'Off'}
            </span>
          </label>
        );

      case 'select':
        return (
          <select
            className={`${CONTROL_CLASSES} w-44`}
            value={typeof value === 'string' ? value : String(value ?? '')}
            onChange={(event) => updateField(key, event.target.value)}
          >
            {(field.options ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        );

      case 'number':
        return (
          <input
            type="number"
            step={field.step ?? '1'}
            className={`${CONTROL_CLASSES} w-32`}
            value={typeof value === 'number' ? value : Number(value ?? 0)}
            onChange={(event) => {
              const parsed = Number(event.target.value);
              updateField(key, Number.isNaN(parsed) ? 0 : parsed);
            }}
          />
        );

      case 'json':
        return (
          <pre className="overflow-x-auto rounded-md bg-surface-alt p-2 font-mono text-xs text-text">
            {JSON.stringify(value)}
          </pre>
        );

      default:
        return (
          <input
            type="text"
            className={`${CONTROL_CLASSES} w-56`}
            value={typeof value === 'string' ? value : String(value ?? '')}
            onChange={(event) => updateField(key, event.target.value)}
          />
        );
    }
  };

  const header = (
    <PageHeader
      title="Settings"
      description="Company-scoped configuration for this business."
      actions={
        can('settings.update') ? (
          <Button
            variant="primary"
            icon="save-outline"
            disabled={changedKeys.length === 0 || saveMutation.isPending}
            loading={saveMutation.isPending}
            onClick={handleSave}
          >
            {changedKeys.length > 0
              ? `Save changes (${changedKeys.length})`
              : 'Save changes'}
          </Button>
        ) : null
      }
    />
  );

  if (companyId === null) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <Card>
          <CardBody>
            <EmptyState
              icon="business-outline"
              title="Select a company"
              description="Settings are scoped to a company. Choose one from the switcher in the top bar to view and edit its configuration."
            />
          </CardBody>
        </Card>
      </div>
    );
  }

  if (settingsQuery.isLoading) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <Card>
          <LoadingState label="Loading settings..." />
        </Card>
      </div>
    );
  }

  if (settingsQuery.isError) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <Card>
          <ErrorState
            message={settingsQuery.error?.message ?? 'Failed to load settings'}
            onRetry={() => settingsQuery.refetch()}
          />
        </Card>
      </div>
    );
  }

  const groups = (response?.groups ?? []).filter((group) =>
    Object.keys(values).some((key) => key.split('.')[0] === group)
  );

  if (groups.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <Card>
          <CardBody>
            <EmptyState
              icon="settings-outline"
              title="No settings yet"
              description="This company does not have any configurable settings yet. New keys appear here once they are defined."
            />
          </CardBody>
        </Card>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {header}

      {groups.map((group) => {
        const groupKeys = Object.keys(values).filter(
          (key) => key.split('.')[0] === group
        );

        return (
          <Card key={group}>
            <CardHeader
              title={GROUP_LABELS[group] ?? group}
              description={`${groupKeys.length} setting${groupKeys.length === 1 ? '' : 's'}`}
            />
            <CardBody className="flex flex-col divide-y divide-border p-0">
              {groupKeys.map((key) => (
                <div
                  key={key}
                  className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-text">
                      {resolveField(key, values[key]).label}
                    </p>
                    <p className="font-mono text-[10px] text-text-subtle">
                      {key}
                    </p>
                  </div>
                  <div className="shrink-0">{renderControl(key)}</div>
                </div>
              ))}
            </CardBody>
          </Card>
        );
      })}
    </div>
  );
}
