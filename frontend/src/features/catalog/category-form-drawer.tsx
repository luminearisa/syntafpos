import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { categoryApi } from '@/api/services';
import type { Category } from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';

interface CategoryFormDrawerProps {
  open: boolean;
  onClose: () => void;
  initial?: Category | null;
}

interface CategoryFormValues {
  parent_id: number | '';
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

/**
 * Collect a category and everything beneath it so the parent select never
 * offers a node that would create a cycle.
 */
function collectDescendantIds(category: Category): Set<number> {
  const ids = new Set<number>([category.id]);

  (category.children ?? []).forEach((child) => {
    collectDescendantIds(child).forEach((id) => ids.add(id));
  });

  return ids;
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

export function CategoryFormDrawer({
  open,
  onClose,
  initial,
}: CategoryFormDrawerProps) {
  const isEditing = initial !== null && initial !== undefined;
  const id = initial?.id ?? 0;
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: treeData } = useQuery({
    queryKey: ['categories', 'drawer-options', companyId],
    queryFn: () =>
      categoryApi.list({ company_id: companyId ?? undefined, per_page: 500 }),
    enabled: open,
  });

  const forbiddenIds = initial ? collectDescendantIds(initial) : new Set<number>();

  // Build the flat, indented option list the parent select shows.
  const parentOptions: { label: string; value: number }[] = [];
  const walk = (nodes: Category[], depth: number) => {
    nodes.forEach((node) => {
      if (forbiddenIds.has(node.id)) {
        return;
      }

      parentOptions.push({
        value: node.id,
        label: `${'  '.repeat(depth)}${depth > 0 ? '└ ' : ''}${node.name}`,
      });

      if (node.children && node.children.length > 0) {
        walk(node.children, depth + 1);
      }
    });
  };
  walk(treeData?.data ?? [], 0);

  const [form, setForm] = useState<CategoryFormValues>({
    parent_id: '',
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
        parent_id: initial.parent_id ?? '',
        code: initial.code ?? '',
        name: initial.name ?? '',
        description: initial.description ?? '',
        status: initial.status === 'inactive' ? 'inactive' : 'active',
      });
    } else {
      setForm({
        parent_id: '',
        code: '',
        name: '',
        description: '',
        status: 'active',
      });
    }

    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: CategoryFormValues) => {
      const payload = {
        parent_id: values.parent_id === '' ? null : values.parent_id,
        code: values.code,
        name: values.name,
        description: emptyToNull(values.description),
        status: values.status,
      };

      return isEditing
        ? categoryApi.update(id, payload)
        : categoryApi.create(payload);
    },
    onSuccess: () => {
      toast({
        title: isEditing ? 'Category updated' : 'Category created',
        variant: 'success',
      });
      invalidate(listQueryKeys.categories);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: isEditing ? 'Failed to update category' : 'Failed to create category',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof CategoryFormValues>(
    field: K,
    value: CategoryFormValues[K]
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
      title={isEditing ? 'Edit category' : 'New category'}
      description={
        isEditing
          ? 'Update the category. Leave the parent empty to keep it at the top level.'
          : 'Add a product category. Nest it under a parent to build a tree.'
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
            form="category-form"
            variant="primary"
            size="sm"
            icon={isEditing ? 'save-outline' : 'add-outline'}
            loading={mutation.isPending}
          >
            {isEditing ? 'Save changes' : 'Create category'}
          </Button>
        </>
      }
    >
      <form
        id="category-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
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
            label="Parent category"
            name="parent_id"
            options={parentOptions}
            placeholder="Top level (no parent)"
            value={form.parent_id}
            onChange={(event) =>
              setField(
                'parent_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.parent_id}
            wrapperClassName="sm:col-span-2 lg:col-span-3"
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
