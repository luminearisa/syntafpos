import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saleApi } from '@/api/services';
import type { Sale } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { listQueryKeys } from '@/lib/query-client';
import { Modal } from '@/components/ui/overlay';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';

/**
 * Void an open ticket with a reason (Phase 3.5).
 *
 * Void and cancel both withdraw a ticket, but they are not the same act: cancel
 * is a cashier abandoning a ticket, void is permission-gated and must say why,
 * and is written to the audit log as `sale.void`. The reason is required here
 * and on the server, because a withdrawal someone has to explain is the whole
 * point of the separate permission.
 *
 * A completed sale is never voided — that is a return and a refund, and the
 * button that opens this dialog is not offered for one.
 */
export function VoidSaleDialog({
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
  const [reason, setReason] = useState('');

  const mutation = useMutation({
    mutationFn: () => saleApi.void(sale.id, reason.trim()),
    onSuccess: (response) => {
      client.invalidateQueries({ queryKey: ['sales', sale.id, 'detail'] });
      client.invalidateQueries({ queryKey: listQueryKeys.sales });
      client.invalidateQueries({ queryKey: listQueryKeys.refunds });
      // Voiding a ticket voids its tenders, so any shift holding that cash is
      // short by exactly what this one took.
      client.invalidateQueries({ queryKey: ['register-sessions'] });

      toast({
        variant: 'success',
        title: `Sale ${response.data.number} voided`,
        message: 'Stock has been returned through the ledger and the tenders are voided.',
      });

      setReason('');
      onClose();
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Could not void this sale',
        message: apiErrorMessage(error, 'The sale was left as it was.'),
      });
    },
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Void this sale?"
      description={`${sale.number} will be withdrawn and the action recorded against your name.`}
      size="md"
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            icon="close-circle-outline"
            loading={mutation.isPending}
            disabled={reason.trim() === ''}
            onClick={() => mutation.mutate()}
          >
            Void sale
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-text-muted">
        <p>
          Stock already deducted goes back through the ledger, and recorded tenders are
          voided. Cash is not refunded here — that is a return and refund flow.
        </p>
        <Textarea
          name="void_reason"
          label="Reason (required)"
          placeholder="e.g. duplicate ticket, wrong customer"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          autoFocus
        />
      </div>
    </Modal>
  );
}