import { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { Sale } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { formatDecimal, formatMoneyString } from '@/utils/format';
import { listQueryKeys } from '@/lib/query-client';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Input, Textarea } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';

/**
 * Receive goods back against a sale (Phase 3.5).
 *
 * The form shows each line's original quantity and what is still returnable, so
 * a cashier can never promise back more than came out. The client sends only the
 * sale line and the quantity — never a price — and the engine reads the sale
 * line's own frozen figures to derive what the return is worth, exactly as
 * checkout refuses a client-sent total.
 *
 * A fully returned line is hidden rather than disabled: there is nothing left to
 * do with it, and offering a greyed-out row invites a click that will fail.
 */
export function RecordReturnDialog({
  open,
  onClose,
  sale,
}: {
  open: boolean;
  onClose: () => void;
  sale: Sale;
}) {
  const client = useQueryClient();
  const { toast } = useToast();

  const lines = useMemo(
    () => (sale.items ?? []).filter((item) => Number(item.returnable_quantity) > 0),
    [sale.items]
  );

  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');

  const chosen = lines
    .map((item) => ({ item, quantity: quantities[item.id] ?? '' }))
    .filter((entry) => Number(entry.quantity) > 0);

  const overReturned = chosen.find(
    ({ item, quantity }) => Number(quantity) > Number(item.returnable_quantity)
  );

  const invalid =
    chosen.length === 0 || overReturned !== undefined || reason.trim() === '';

  const mutation = useMutation({
    mutationFn: () =>
      saleApi.raiseReturn(sale.id, {
        reason: reason.trim(),
        notes: notes.trim() === '' ? null : notes.trim(),
        items: chosen.map(({ item, quantity }) => ({
          sale_item_id: item.id,
          quantity,
        })),
      }),
    onSuccess: (response) => {
      client.invalidateQueries({ queryKey: ['sales', sale.id, 'detail'] });
      client.invalidateQueries({ queryKey: listQueryKeys.sales });
      client.invalidateQueries({ queryKey: listQueryKeys.saleReturns });
      client.invalidateQueries({ queryKey: listQueryKeys.stockMovements });

      toast({
        variant: 'success',
        title: `Return ${response.data.number} recorded`,
        message: 'Stock is back in the ledger. Raise a refund to give the money back.',
      });

      setQuantities({});
      setReason('');
      setNotes('');
      onClose();
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Could not record the return',
        message: apiErrorMessage(error, 'Nothing was returned.'),
      });
    },
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Record a return"
      description={`${sale.number} · choose what is coming back`}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="primary"
            icon="return-down-back-outline"
            loading={mutation.isPending}
            disabled={invalid}
            onClick={() => mutation.mutate()}
          >
            Record return
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {lines.length === 0 ? (
          <p className="text-sm text-text-muted">
            Every line on this sale has already come back. There is nothing left to return.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-md border border-border">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-border bg-surface-alt text-xs text-text-muted">
                  <th scope="col" className="px-3 py-2 text-left font-semibold">Product</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Sold</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Returnable</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Price</th>
                  <th scope="col" className="px-3 py-2 text-right font-semibold">Returning</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((item) => {
                  const quantity = quantities[item.id] ?? '';
                  const tooMany = Number(quantity) > Number(item.returnable_quantity);

                  return (
                    <tr key={item.id} className="border-b border-border last:border-0">
                      <td className="px-3 py-2">
                        <div className="flex flex-col">
                          <span className="font-medium text-text">{item.product_name}</span>
                          <span className="font-mono text-[11px] text-text-subtle">
                            {item.product_sku}
                            {item.variant_name ? ` · ${item.variant_name}` : ''}
                          </span>
                        </div>
                      </td>
                      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
                        {formatDecimal(item.quantity, 6)}
                      </td>
                      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
                        {formatDecimal(item.returnable_quantity, 6)}
                      </td>
                      <td className="px-3 py-2 text-right font-mono tabular-nums text-text-muted">
                        {formatMoneyString(item.unit_price)}
                      </td>
                      <td className="px-3 py-2">
                        <input
                          aria-label={`Quantity returning for ${item.product_name}`}
                          inputMode="decimal"
                          className={
                            'h-8 w-24 rounded-md border bg-surface px-2 text-right font-mono text-sm text-text ' +
                            (tooMany
                              ? 'border-danger focus:border-danger focus:outline-none focus:ring-1 focus:ring-danger'
                              : 'border-border focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary')
                          }
                          value={quantity}
                          onChange={(event) =>
                            setQuantities((current) => ({
                              ...current,
                              [item.id]: event.target.value,
                            }))
                          }
                          placeholder="0"
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {overReturned && (
          <p className="text-xs text-danger">
            {overReturned.item.product_name}: only{' '}
            {formatDecimal(overReturned.item.returnable_quantity, 6)} is left to return.
          </p>
        )}

        <Textarea
          name="return_reason"
          label="Reason"
          placeholder="e.g. damaged on arrival"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />

        <Input
          name="return_notes"
          label="Notes (optional)"
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  );
}