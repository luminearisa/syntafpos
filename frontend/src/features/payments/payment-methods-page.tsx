import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { paymentMethodApi } from '@/api/services';
import type { PaymentChannel, PaymentMethod } from '@/types';
import { useListQuery, useInvalidateList } from '@/hooks/use-list-query';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { useToast } from '@/components/ui/toast';
import { DataTable, type Column } from '@/components/data-display/data-table';
import { Button } from '@/components/ui/button';
import { ConfirmModal, IconButton } from '@/components/ui/overlay';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/state';
import { Input, Select } from '@/components/ui/input';
import { apiErrorMessage, apiFieldErrors } from '@/utils/api-error';
import { PaymentMethodFormDrawer } from './payment-method-form-drawer';

/**
 * The shop's own list of ways to be paid.
 *
 * Master data like any other screen here, with the one difference that money has
 * already flowed over these rows: a method that has taken payment cannot be deleted
 * and cannot change channel, so its trash icon is still there but the server will
 * explain itself, and the drawer locks the channel field when payments exist.
 */
const channelOptions: { label: string; value: PaymentChannel }[] = [
  { label: 'Cash', value: 'cash' },
  { label: 'Bank transfer', value: 'bank_transfer' },
  { label: 'Debit card', value: 'debit' },
  { label: 'Credit card', value: 'credit_card' },
  { label: 'QRIS', value: 'qris' },
  { label: 'E-wallet', value: 'e_wallet' },
  { label: 'Virtual account', value: 'virtual_account' },
  { label: 'Customer credit', value: 'customer_credit' },
  { label: 'Other', value: 'other' },
];

const activeOptions = [
  { label: 'Active and inactive', value: '' },
  { label: 'Active only', value: '1' },
];

export default function PaymentMethodsPage() {
  const can = useAuthStore((state) => state.can);
  const companyId = useAuthStore((state) => state.scope.companyId);
  const { toast } = useToast();
  const invalidate = useInvalidateList();

  const list = useListQuery<PaymentMethod>(
    listQueryKeys.paymentMethods,
    (params) => paymentMethodApi.list(params),
    { company_id: companyId ?? undefined }
  );

  // Keep the list in sync when the active company changes in the topbar.
  useEffect(() => {
    list.onParamsChange({ company_id: companyId ?? undefined, page: 1 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId]);

  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<PaymentMethod | null>(null);
  const [removeTarget, setRemoveTarget] = useState<PaymentMethod | null>(null);

  const remove = useMutation({
    mutationFn: (id: number) => paymentMethodApi.remove(id),
    onSuccess: () => {
      toast({ title: 'Payment method deleted', variant: 'success' });
      invalidate(listQueryKeys.paymentMethods);
      setRemoveTarget(null);
    },
    onError: (error) => {
      const fields = apiFieldErrors(error);

      toast({
        title: 'Could not delete this method',
        // The server's refusal under `payment_method` is the useful sentence —
        // "deactivate it instead" — so it goes in front of the generic message.
        message:
          fields.payment_method ??
          apiErrorMessage(error, 'Failed to delete payment method'),
        variant: 'error',
      });
      setRemoveTarget(null);
    },
  });

  const openCreate = () => {
    setEditTarget(null);
    setFormOpen(true);
  };

  const openEdit = (method: PaymentMethod) => {
    setEditTarget(method);
    setFormOpen(true);
  };

  const openRemove = (method: PaymentMethod) => {
    remove.reset();
    setRemoveTarget(method);
  };

  const columns: Column<PaymentMethod>[] = [
    {
      key: 'sort_order',
      header: 'Order',
      align: 'right',
      width: '4.5rem',
      render: (row) => (
        <span className="font-mono text-xs text-text-subtle">{row.sort_order}</span>
      ),
    },
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      width: '9rem',
      render: (row) => <span className="font-mono text-xs">{row.code}</span>,
    },
    {
      key: 'name',
      header: 'Name at the till',
      sortable: true,
      render: (row) => (
        <span className="font-medium text-text">
          {row.name}
          {row.is_default && (
            <Badge variant="primary" icon="star" className="ml-2">
              Default
            </Badge>
          )}
        </span>
      ),
    },
    {
      key: 'channel',
      header: 'Channel',
      render: (row) => (
        <span className="text-text-muted">
          {row.channel_label}
          {row.takes_tender && (
            <span className="ml-2 text-[10px] text-success" title="Hands change back">
              · change
            </span>
          )}
          {row.uses_customer_account && (
            <span className="ml-2 text-[10px] text-warning" title="Draws down a customer account">
              · account
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'requires_reference',
      header: 'Reference',
      render: (row) =>
        row.requires_reference ? (
          <span className="text-text-muted">Required</span>
        ) : (
          <span className="text-text-subtle">-</span>
        ),
    },
    {
      key: 'provider',
      header: 'Provider',
      render: (row) =>
        row.provider ? (
          <span className="font-mono text-xs text-text-muted">{row.provider}</span>
        ) : (
          <span className="text-text-subtle">at counter</span>
        ),
    },
    {
      key: 'payments_count',
      header: 'Payments',
      align: 'right',
      render: (row) => (
        <span className="font-mono text-sm text-text-muted">
          {row.payments_count ?? 0}
        </span>
      ),
    },
    {
      key: 'is_active',
      header: 'Active',
      render: (row) =>
        row.is_active ? (
          <Badge variant="success" icon="checkmark-circle">
            Active
          </Badge>
        ) : (
          <Badge variant="default" icon="ellipse-outline">
            Inactive
          </Badge>
        ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (row) => (
        <div className="flex items-center justify-end gap-0.5">
          {can('payment_methods.update') && (
            <IconButton
              icon="create-outline"
              label={`Edit ${row.name}`}
              onClick={() => openEdit(row)}
            />
          )}
          {can('payment_methods.delete') && (
            <IconButton
              icon="trash-outline"
              label={`Delete ${row.name}`}
              className="hover:text-danger"
              onClick={() => openRemove(row)}
            />
          )}
        </div>
      ),
    },
  ];

  const canCreate = can('payment_methods.create');
  const errorMessage = list.error
    ? list.error.message || 'Failed to load payment methods'
    : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Payment methods"
        description="How this shop can be paid: the buttons the till shows, in the order it shows them. A method that has taken money keeps its channel and cannot be deleted."
        actions={
          canCreate ? (
            <Button variant="primary" icon="add-outline" onClick={openCreate}>
              New method
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-end gap-3">
        <Select
          label="Channel"
          name="channel_filter"
          options={channelOptions}
          placeholder="All channels"
          value={list.params.channel ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              channel: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[12rem]"
        />

        <Select
          label="Status"
          name="active_filter"
          options={activeOptions}
          value={list.params.active_only ?? ''}
          onChange={(event) =>
            list.onParamsChange({
              active_only: event.target.value === '' ? undefined : event.target.value,
              page: 1,
            })
          }
          wrapperClassName="w-full max-w-[12rem]"
        />

        <Input
          type="search"
          label="Name or code"
          name="payment_method_search"
          value={String(list.params.search ?? '')}
          onChange={(event) =>
            list.onParamsChange({ search: event.target.value || undefined, page: 1 })
          }
          wrapperClassName="w-full max-w-[16rem]"
        />
      </div>

      <DataTable
        columns={columns}
        rows={list.rows}
        rowKey={(row) => row.id ?? row.code}
        loading={list.isLoading}
        error={errorMessage}
        onRetry={list.refetch}
        pagination={list.pagination}
        params={list.params}
        onParamsChange={list.onParamsChange}
        searchPlaceholder="Search payment methods..."
        emptyTitle="No payment methods configured"
        emptyDescription="Until you add one, the till offers the standard set — cash, QRIS, debit, credit card, e-wallet, bank transfer."
        emptyAction={
          canCreate
            ? { label: 'New method', onClick: openCreate, icon: 'add-outline' }
            : undefined
        }
      />

      <PaymentMethodFormDrawer
        open={formOpen}
        onClose={() => setFormOpen(false)}
        initial={editTarget}
      />

      <ConfirmModal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && remove.mutate(removeTarget.id ?? 0)}
        title="Delete payment method"
        message={
          <>
            Are you sure you want to delete{' '}
            <strong className="text-text">{removeTarget?.name}</strong>?{' '}
            {(removeTarget?.payments_count ?? 0) > 0
              ? `${removeTarget?.payments_count} payment(s) were taken on it, so the server will refuse this and ask you to deactivate it instead.`
              : 'Payments already recorded keep their own copy of its name and channel.'}
          </>
        }
        confirmLabel="Delete method"
        variant="danger"
        loading={remove.isPending}
      />
    </div>
  );
}
