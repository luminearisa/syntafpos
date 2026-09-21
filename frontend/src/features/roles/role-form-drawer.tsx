import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { permissionApi, roleApi } from '@/api/services';
import { useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Textarea } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/state';
import { useToast } from '@/components/ui/toast';
import type { Permission, Role } from '@/types';

const NEW = 'new';
const CLOSED = 'closed';

// A stable reference keeps the `grouped` memo valid while the query is loading.
const EMPTY_PERMISSIONS: Permission[] = [];

interface RoleFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Role | null;
}

interface GroupedPermissions {
  group: string;
  items: Permission[];
}

const GROUP_LABELS: Record<string, string> = {
  system: 'System',
  companies: 'Companies',
  branches: 'Branches',
  warehouses: 'Warehouses',
  registers: 'Registers',
  users: 'Users',
  roles: 'Roles',
  settings: 'Settings',
  audit: 'Audit',
};

function groupLabel(group: string): string {
  return (
    GROUP_LABELS[group] ??
    group.charAt(0).toUpperCase() + group.slice(1)
  );
}

function extractApiFailure(
  error: unknown
): { message: string; errors: Record<string, string[]> } {
  const fallback = { message: 'Something went wrong', errors: {} };

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

export function RoleFormDrawer({ open, onClose, initial }: RoleFormDrawerProps) {
  const isEditing = !!initial;
  const [name, setName] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [description, setDescription] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const permissionsQuery = useQuery({
    queryKey: [...listQueryKeys.permissions, 'all'],
    queryFn: async () => (await permissionApi.list()).data,
  });

  const permissions = permissionsQuery.data ?? EMPTY_PERMISSIONS;

  // The permission payload does not always echo the grouping column, so fall
  // back to the module prefix of the permission name.
  const grouped: GroupedPermissions[] = useMemo(() => {
    const byGroup = new Map<string, Permission[]>();

    permissions.forEach((permission) => {
      const group =
        permission.group || permission.name.split('.')[0] || 'other';
      const existing = byGroup.get(group);

      if (existing) {
        existing.push(permission);
      } else {
        byGroup.set(group, [permission]);
      }
    });

    return Array.from(byGroup, ([group, items]) => ({ group, items }));
  }, [permissions]);

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a new target.
  const target = open ? (initial ?? NEW) : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setName(initial?.name ?? '');
    setDisplayName(initial?.display_name ?? '');
    setDescription(initial?.description ?? '');
    setSelected(initial?.permissions ?? []);
    setFieldErrors({});
  }

  const togglePermission = (permissionName: string) => {
    setSelected((current) =>
      current.includes(permissionName)
        ? current.filter((value) => value !== permissionName)
        : [...current, permissionName]
    );
  };

  const toggleGroup = (group: GroupedPermissions) => {
    const names = group.items.map((item) => item.name);
    const allSelected = names.every((itemName) => selected.includes(itemName));

    setSelected((current) => {
      const rest = current.filter((itemName) => !names.includes(itemName));
      return allSelected ? rest : [...rest, ...names];
    });
  };

  const saveMutation = useMutation({
    mutationFn: () => {
      const trimmedDisplay = displayName.trim();
      const payload: Record<string, unknown> = {
        display_name: isEditing
          ? trimmedDisplay || null
          : trimmedDisplay || name.trim(),
        description: description.trim() || null,
        permissions: selected,
      };

      if (!isEditing) {
        payload['name'] = name.trim();
      }

      return isEditing && initial
        ? roleApi.update(initial.id, payload)
        : roleApi.create(payload);
    },
    onSuccess: () => {
      invalidate(listQueryKeys.roles);
      toast({
        title: isEditing ? 'Role updated' : 'Role created',
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

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width="max-w-2xl"
      title={isEditing ? 'Edit role' : 'New role'}
      description={
        isEditing
          ? 'Adjust the label and the permissions this role grants.'
          : 'Define a role and pick the permissions it grants.'
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
            {isEditing ? 'Save changes' : 'Create role'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <FieldGroup title="Details">
          <Input
            name="name"
            label="Role name"
            placeholder="store_manager"
            value={name}
            error={fieldErrors['name']}
            disabled={isEditing}
            hint={isEditing ? 'Role names cannot be renamed' : undefined}
            onChange={(event) => setName(event.target.value)}
          />

          <Input
            name="display_name"
            label="Display name"
            placeholder="Store Manager"
            value={displayName}
            error={fieldErrors['display_name']}
            onChange={(event) => setDisplayName(event.target.value)}
          />

          <Textarea
            name="description"
            label="Description"
            placeholder="What can this role do?"
            value={description}
            error={fieldErrors['description']}
            onChange={(event) => setDescription(event.target.value)}
          />
        </FieldGroup>

        <FieldGroup title="Permissions">
          <div className="sm:col-span-2 lg:col-span-3">
            {permissionsQuery.isLoading ? (
              <LoadingState label="Loading permissions..." />
            ) : permissionsQuery.isError ? (
              <p className="text-sm text-danger">
                Failed to load permissions. Close and reopen this panel to
                retry.
              </p>
            ) : (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {grouped.map((group) => {
                  const names = group.items.map((item) => item.name);
                  const allSelected = names.every((itemName) =>
                    selected.includes(itemName)
                  );

                  return (
                    <fieldset
                      key={group.group}
                      className="rounded-md border border-border p-3"
                    >
                      <legend className="flex items-center gap-2 px-1">
                        <input
                          type="checkbox"
                          checked={allSelected}
                          onChange={() => toggleGroup(group)}
                          className="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                        />
                        <span className="text-xs font-semibold text-text">
                          {groupLabel(group.group)}
                        </span>
                      </legend>

                      <div className="mt-1.5 flex flex-col gap-1">
                        {group.items.map((permission) => (
                          <label
                            key={permission.name}
                            className="flex cursor-pointer items-center gap-2 text-sm"
                          >
                            <input
                              type="checkbox"
                              checked={selected.includes(permission.name)}
                              onChange={() => togglePermission(permission.name)}
                              className="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                            />
                            <span className="text-text">
                              {permission.display_name || permission.name}
                            </span>
                          </label>
                        ))}
                      </div>
                    </fieldset>
                  );
                })}
              </div>
            )}
          </div>
        </FieldGroup>
      </div>
    </Drawer>
  );
}
