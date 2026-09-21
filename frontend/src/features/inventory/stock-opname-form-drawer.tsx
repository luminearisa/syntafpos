import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { stockOpnameApi, warehouseApi } from '@/api/services';
import { listQueryKeys } from '@/lib/query-client';
import { useInvalidateList } from '@/hooks/use-list-query';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/overlay';
import { FieldGroup, Input, Select, Textarea } from '@/components/ui/input';

interface StockOpnameFormDrawerProps {
  open: boolean;
  onClose: () => void;
}

interface StockOpnameFormValues {
  warehouse_id: number | '';
  opname_date: string;
  notes: string;
}

const NEW = 'new';
const CLOSED = 'closed';

function today(): string {
  return new Date().toISOString().slice(0, 10);
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

/**
 * Raises a draft count sheet. The drawer collects only the header fields the
 * create endpoint accepts: the warehouse, the count date and a note.
 *
 * Item lines are deliberately not built here. A count sheet is opened first and
 * its lines are entered while counting (system_quantity is snapshotted by the
 * service, never trusted from the client), so the drawer stays header-only.
 */
export function StockOpnameFormDrawer({ open, onClose }: StockOpnameFormDrawerProps) {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const scopeWarehouseId = useAuthStore((state) => state.scope.warehouseId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const { data: warehouseOptionsData } = useQuery({
    queryKey: ['warehouses', 'drawer-options', companyId],
    queryFn: () => warehouseApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: open && companyId !== null,
  });
  const warehouseOptions = (warehouseOptionsData?.data ?? []).map((warehouse) => ({
    label: warehouse.name,
    value: warehouse.id,
  }));

  const [form, setForm] = useState<StockOpnameFormValues>(() => ({
    warehouse_id: scopeWarehouseId ?? '',
    opname_date: today(),
    notes: '',
  }));
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Adjusting state during render (rather than in an effect) avoids a
  // cascading render each time the drawer opens onto a fresh count sheet.
  const target = open ? NEW : CLOSED;
  const [resetKey, setResetKey] = useState(target);
  if (target !== resetKey) {
    setResetKey(target);
    setForm({
      warehouse_id: scopeWarehouseId ?? '',
      opname_date: today(),
      notes: '',
    });
    setFormErrors({});
  }

  const mutation = useMutation({
    mutationFn: (values: StockOpnameFormValues) =>
      stockOpnameApi.create({
        company_id: companyId ?? undefined,
        warehouse_id: values.warehouse_id === '' ? undefined : values.warehouse_id,
        opname_date: values.opname_date,
        notes: values.notes.trim() === '' ? undefined : values.notes,
      }),
    onSuccess: () => {
      toast({ title: 'Count sheet created', variant: 'success' });
      invalidate(listQueryKeys.stockOpnames);
      onClose();
    },
    onError: (error) => {
      const { fieldErrors, message } = extractApiErrors(error);
      setFormErrors(fieldErrors);
      toast({
        title: 'Failed to create count sheet',
        message,
        variant: 'error',
      });
    },
  });

  const setField = <K extends keyof StockOpnameFormValues>(
    field: K,
    value: StockOpnameFormValues[K]
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
      title="New stock count"
      description="Open a draft count sheet for one warehouse. Lines are entered while counting."
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
            form="stock-opname-form"
            variant="primary"
            size="sm"
            icon="add-outline"
            loading={mutation.isPending}
          >
            Create count sheet
          </Button>
        </>
      }
    >
      <form
        id="stock-opname-form"
        onSubmit={handleSubmit}
        className="flex flex-col gap-4"
      >
        <FieldGroup title="Count details">
          <Select
            label="Warehouse"
            name="warehouse_id"
            options={warehouseOptions}
            placeholder="Select warehouse"
            value={form.warehouse_id}
            onChange={(event) =>
              setField(
                'warehouse_id',
                event.target.value === '' ? '' : Number(event.target.value)
              )
            }
            error={formErrors.warehouse_id}
            wrapperClassName="sm:col-span-2"
            required
          />
          <Input
            label="Count date"
            name="opname_date"
            type="date"
            value={form.opname_date}
            onChange={(event) => setField('opname_date', event.target.value)}
            error={formErrors.opname_date}
            required
          />
        </FieldGroup>

        <FieldGroup title="Notes">
          <Textarea
            label="Notes"
            name="notes"
            value={form.notes}
            onChange={(event) => setField('notes', event.target.value)}
            error={formErrors.notes}
            placeholder="Anything the counting team should know..."
            wrapperClassName="sm:col-span-2 lg:col-span-3"
          />
        </FieldGroup>

        <p className="text-xs leading-relaxed text-text-subtle">
          Item lines are entered at count time: once the sheet exists, start
          counting to snapshot system quantities and record the counted
          quantities line by line.
        </p>
      </form>
    </Drawer>
  );
}
