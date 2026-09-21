import { useState } from 'react';
import { useMutation, useQueries, useQuery } from '@tanstack/react-query';
import {
  branchApi,
  registerApi,
  roleApi,
  userApi,
  warehouseApi,
} from '@/api/services';
import { useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import type {
  Branch,
  Register,
  Role,
  User,
  UserStatus,
  Warehouse,
} from '@/types';

const NEW = 'new';
const CLOSED = 'closed';

interface UserFormState {
  name: string;
  email: string;
  password: string;
  phone: string;
  status: UserStatus;
  role_ids: number[];
  company_ids: number[];
  branch_ids: number[];
  warehouse_ids: number[];
  register_ids: number[];
}

type IdsKey =
  | 'role_ids'
  | 'company_ids'
  | 'branch_ids'
  | 'warehouse_ids'
  | 'register_ids';

const IDS_KEYS: IdsKey[] = [
  'role_ids',
  'company_ids',
  'branch_ids',
  'warehouse_ids',
  'register_ids',
];

interface UserFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: User | null;
}

interface CheckOption {
  id: number;
  label: string;
  hint?: string;
}

interface ApiFailure {
  message: string;
  errors: Record<string, string[]>;
}

function emptyForm(): UserFormState {
  return {
    name: '',
    email: '',
    password: '',
    phone: '',
    status: 'active',
    role_ids: [],
    company_ids: [],
    branch_ids: [],
    warehouse_ids: [],
    register_ids: [],
  };
}

/**
 * The backend reports validation failures in `errors` with a generic envelope
 * `message`, so prefer the first field-level message when it is present.
 */
function extractApiFailure(error: unknown): ApiFailure {
  const fallback: ApiFailure = { message: 'Something went wrong', errors: {} };

  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const body = (error as { response?: { data?: Record<string, unknown> } })
      .response?.data;

    if (body) {
      const errors = (body['errors'] as Record<string, string[]>) ?? {};
      const values = Object.values(errors);
      const first = values.length > 0 ? (values[0]?.[0] ?? undefined) : undefined;

      return {
        message:
          first ??
          (typeof body['message'] === 'string' ? body['message'] : fallback.message),
        errors,
      };
    }
  }

  if (error instanceof Error) {
    return { message: error.message, errors: {} };
  }

  return fallback;
}

function mapFieldErrors(
  errors: Record<string, string[]>
): Record<string, string> {
  const mapped: Record<string, string> = {};

  Object.entries(errors).forEach(([key, messages]) => {
    if (messages.length > 0) {
      mapped[key] = messages[0] ?? '';
    }
  });

  return mapped;
}

function CheckboxList({
  options,
  selected,
  onChange,
  emptyMessage,
}: {
  options: CheckOption[];
  selected: number[];
  onChange: (ids: number[]) => void;
  emptyMessage: string;
}) {
  if (options.length === 0) {
    return <p className="text-xs text-text-subtle">{emptyMessage}</p>;
  }

  const toggle = (id: number) => {
    onChange(
      selected.includes(id)
        ? selected.filter((value) => value !== id)
        : [...selected, id]
    );
  };

  return (
    <div className="grid max-h-44 grid-cols-1 gap-1.5 overflow-y-auto sm:grid-cols-2">
      {options.map((option) => (
        <label
          key={option.id}
          className="flex cursor-pointer items-center gap-2 rounded-md border border-border bg-surface px-2.5 py-1.5 text-sm hover:bg-surface-alt"
        >
          <input
            type="checkbox"
            checked={selected.includes(option.id)}
            onChange={() => toggle(option.id)}
            className="h-4 w-4 rounded border-border text-primary focus:ring-primary"
          />
          <span className="min-w-0 flex-1 truncate text-text">
            {option.label}
          </span>
          {option.hint && (
            <span className="shrink-0 text-[10px] text-text-subtle">
              {option.hint}
            </span>
          )}
        </label>
      ))}
    </div>
  );
}

export function UserFormDrawer({ open, onClose, initial }: UserFormDrawerProps) {
  const isEditing = !!initial;
  const [form, setForm] = useState<UserFormState>(emptyForm);
  // Grants are only sent when the user actually edited them: the backend keeps
  // untouched *_ids keys as-is.
  const [touched, setTouched] = useState<Set<IdsKey>>(new Set());
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const { toast } = useToast();
  const invalidate = useInvalidateList();
  const authCompanies = useAuthStore((state) => state.user?.companies) ?? [];

  const rolesQuery = useQuery({
    queryKey: [...listQueryKeys.roles, 'form'],
    queryFn: () => roleApi.list({ per_page: 100 }),
  });

  // Branches, warehouses and registers are scoped per company, so one request
  // runs for every granted company and the results are merged.
  const branchQueries = useQueries({
    queries: form.company_ids.map((companyId) => ({
      queryKey: [...listQueryKeys.branches, 'by-company', companyId],
      queryFn: () => branchApi.list({ company_id: companyId, per_page: 100 }),
      staleTime: 30_000,
    })),
  });

  const warehouseQueries = useQueries({
    queries: form.company_ids.map((companyId) => ({
      queryKey: [...listQueryKeys.warehouses, 'by-company', companyId],
      queryFn: () =>
        warehouseApi.list({ company_id: companyId, per_page: 100 }),
      staleTime: 30_000,
    })),
  });

  const registerQueries = useQueries({
    queries: form.company_ids.map((companyId) => ({
      queryKey: [...listQueryKeys.registers, 'by-company', companyId],
      queryFn: () => registerApi.list({ company_id: companyId, per_page: 100 }),
      staleTime: 30_000,
    })),
  });

  const roles: Role[] = rolesQuery.data?.data ?? [];
  const branches: Branch[] = branchQueries.flatMap((query) => query.data?.data ?? []);
  const warehouses: Warehouse[] = warehouseQueries.flatMap(
    (query) => query.data?.data ?? []
  );
  const registers: Register[] = registerQueries.flatMap(
    (query) => query.data?.data ?? []
  );

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm(
      initial
        ? {
            name: initial.name,
            email: initial.email,
            password: '',
            phone: initial.phone ?? '',
            status: initial.status,
            role_ids: (initial.roles ?? []).map((role) => role.id),
            company_ids: (initial.companies ?? []).map((company) => company.id),
            branch_ids: (initial.branches ?? []).map((branch) => branch.id),
            warehouse_ids: (initial.warehouses ?? []).map((warehouse) => warehouse.id),
            register_ids: (initial.registers ?? []).map((register) => register.id),
          }
        : emptyForm()
    );
    setTouched(new Set());
    setFieldErrors({});
  }

  const markTouched = (key: IdsKey) => {
    setTouched((current) => {
      if (current.has(key)) {
        return current;
      }

      const next = new Set(current);
      next.add(key);
      return next;
    });
  };

  const handleIdsChange = (key: IdsKey, ids: number[]) => {
    markTouched(key);
    setForm((current) => ({ ...current, [key]: ids }));
  };

  const handleCompanyIdsChange = (ids: number[]) => {
    // Grants must belong to a granted company, so dropping a company prunes its
    // child selections before they can be submitted.
    markTouched('company_ids');
    setForm((current) => {
      const granted = new Set(ids);
      const prune = (list: number[]) => list.filter((id) => granted.has(id));

      return {
        ...current,
        company_ids: ids,
        branch_ids: prune(current.branch_ids),
        warehouse_ids: prune(current.warehouse_ids),
        register_ids: prune(current.register_ids),
      };
    });
  };

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim() || null,
        status: form.status,
      };

      if (isEditing) {
        if (form.password) {
          payload.password = form.password;
        }
      } else {
        payload.password = form.password;
      }

      IDS_KEYS.forEach((key) => {
        if (touched.has(key)) {
          payload[key] = form[key];
        }
      });

      if (initial) {
        return userApi.update(initial.id, payload);
      }

      return userApi.create(payload);
    },
    onSuccess: () => {
      invalidate(listQueryKeys.users);
      toast({
        title: isEditing ? 'User updated' : 'User created',
        variant: 'success',
      });
      onClose();
    },
    onError: (error) => {
      const failure = extractApiFailure(error);
      setFieldErrors(mapFieldErrors(failure.errors));
      toast({ title: failure.message, variant: 'error' });
    },
  });

  const handleSubmit = () => {
    setFieldErrors({});
    saveMutation.mutate();
  };

  const statusOptions = [
    { label: 'Active', value: 'active' },
    { label: 'Suspended', value: 'suspended' },
    { label: 'Inactive', value: 'inactive' },
  ];

  const roleOptions: CheckOption[] = roles.map((role) => ({
    id: role.id,
    label: role.display_name || role.name,
    hint: role.is_system ? 'System' : undefined,
  }));

  const companyOptions: CheckOption[] = authCompanies.map((company) => ({
    id: company.id,
    label: company.name,
    hint: company.code,
  }));

  const branchOptions: CheckOption[] = branches.map((branch) => ({
    id: branch.id,
    label: branch.name,
    hint: branch.code,
  }));

  const warehouseOptions: CheckOption[] = warehouses.map((warehouse) => ({
    id: warehouse.id,
    label: warehouse.name,
    hint: warehouse.code,
  }));

  const registerOptions: CheckOption[] = registers.map((register) => ({
    id: register.id,
    label: register.name,
    hint: register.code,
  }));

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width="max-w-lg"
      title={isEditing ? 'Edit user' : 'New user'}
      description={
        isEditing
          ? 'Update account details, roles and business access.'
          : 'Create an account and grant access to business entities.'
      }
      footer={
        <>
          <Button
            variant="secondary"
            size="sm"
            onClick={onClose}
            disabled={saveMutation.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={handleSubmit}
            loading={saveMutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create user'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <FieldGroup title="Account">
          <Input
            name="name"
            label="Full name"
            placeholder="John Doe"
            value={form.name}
            error={fieldErrors['name']}
            onChange={(event) =>
              setForm((current) => ({ ...current, name: event.target.value }))
            }
          />

          <Input
            name="email"
            label="Email"
            type="email"
            placeholder="john@business.com"
            value={form.email}
            error={fieldErrors['email']}
            onChange={(event) =>
              setForm((current) => ({ ...current, email: event.target.value }))
            }
          />

          <Input
            name="password"
            label="Password"
            type="password"
            placeholder="••••••••"
            value={form.password}
            error={fieldErrors['password']}
            hint={isEditing ? 'Leave blank to keep unchanged' : 'Minimum 8 characters'}
            onChange={(event) =>
              setForm((current) => ({ ...current, password: event.target.value }))
            }
          />

          <Input
            name="phone"
            label="Phone"
            placeholder="0812 0000 0000"
            value={form.phone}
            error={fieldErrors['phone']}
            onChange={(event) =>
              setForm((current) => ({ ...current, phone: event.target.value }))
            }
          />

          <Select
            name="status"
            label="Status"
            options={statusOptions}
            value={form.status}
            error={fieldErrors['status']}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                status: event.target.value as UserStatus,
              }))
            }
          />
        </FieldGroup>

        <FieldGroup title="Roles">
          <div className="sm:col-span-2 lg:col-span-3">
            <CheckboxList
              options={roleOptions}
              selected={form.role_ids}
              onChange={(ids) => handleIdsChange('role_ids', ids)}
              emptyMessage={
                rolesQuery.isLoading
                  ? 'Loading roles...'
                  : 'No roles are available to assign.'
              }
            />
          </div>
        </FieldGroup>

        <FieldGroup title="Business access">
          <div className="sm:col-span-2 lg:col-span-3">
            <p className="mb-1.5 text-xs font-medium text-text-muted">
              Companies
            </p>
            <CheckboxList
              options={companyOptions}
              selected={form.company_ids}
              onChange={handleCompanyIdsChange}
              emptyMessage="No companies are available to grant."
            />
          </div>

          <div className="sm:col-span-2 lg:col-span-3">
            <p className="mb-1.5 text-xs font-medium text-text-muted">
              Branches
            </p>
            <CheckboxList
              options={branchOptions}
              selected={form.branch_ids}
              onChange={(ids) => handleIdsChange('branch_ids', ids)}
              emptyMessage="Select a company to list its branches."
            />
          </div>

          <div className="sm:col-span-2 lg:col-span-3">
            <p className="mb-1.5 text-xs font-medium text-text-muted">
              Warehouses
            </p>
            <CheckboxList
              options={warehouseOptions}
              selected={form.warehouse_ids}
              onChange={(ids) => handleIdsChange('warehouse_ids', ids)}
              emptyMessage="Select a company to list its warehouses."
            />
          </div>

          <div className="sm:col-span-2 lg:col-span-3">
            <p className="mb-1.5 text-xs font-medium text-text-muted">
              Registers
            </p>
            <CheckboxList
              options={registerOptions}
              selected={form.register_ids}
              onChange={(ids) => handleIdsChange('register_ids', ids)}
              emptyMessage="Select a company to list its registers."
            />
          </div>
        </FieldGroup>
      </div>
    </Drawer>
  );
}
