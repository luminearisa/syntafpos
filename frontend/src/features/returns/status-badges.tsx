import { Badge, type BadgeProps } from '@/components/ui/badge';
import { labelFor } from '@/utils/format';
import type { RefundStatus, SaleReturnStatus } from '@/types';

type Variant = BadgeProps['variant'];

/**
 * A return's status as a colour (Phase 3.5).
 *
 * Only `completed` posted stock, so it is the calm green; `draft` is the instant
 * inside the engine's transaction and is grey, and `cancelled` is red. There is
 * no in-between to alarm anyone about.
 */
const returnVariantByStatus: Record<SaleReturnStatus, Variant> = {
  draft: 'default',
  completed: 'success',
  cancelled: 'danger',
};

export function SaleReturnStatusBadge({ status }: { status: SaleReturnStatus | string }) {
  return (
    <Badge variant={returnVariantByStatus[status as SaleReturnStatus] ?? 'default'}>
      {labelFor.saleReturnStatus(status)}
    </Badge>
  );
}

/**
 * A refund's status as a colour.
 *
 * The waiting states are amber and blue, the one in flight is the brand colour,
 * money moved is green, and the two ways it did not are red — so a refund that
 * needs a signature is impossible to miss on a long list.
 */
const refundVariantByStatus: Record<RefundStatus, Variant> = {
  requested: 'warning',
  approved: 'info',
  processing: 'primary',
  completed: 'success',
  failed: 'danger',
  rejected: 'danger',
};

export function RefundStatusBadge({ status }: { status: RefundStatus | string }) {
  return (
    <Badge variant={refundVariantByStatus[status as RefundStatus] ?? 'default'}>
      {labelFor.refundStatus(status)}
    </Badge>
  );
}