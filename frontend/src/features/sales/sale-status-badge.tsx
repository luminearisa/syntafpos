import { Badge, type BadgeProps } from '@/components/ui/badge';
import { labelFor } from '@/utils/format';
import type { SaleStatus } from '@/types';

type Variant = BadgeProps['variant'];

/**
 * A sale's status as a colour, read the way a cashier reads it.
 *
 * The money states are the ones that need attention: a ticket sitting in Draft
 * or Pending Payment is stock that has not left the shelf and takings that have
 * not come in, so it stands out. Completed is calm green, Cancelled is red, and
 * the states in between are counted rather than alarmed.
 */
const variantByStatus: Record<SaleStatus, Variant> = {
  draft: 'default',
  pending_payment: 'warning',
  partially_paid: 'warning',
  paid: 'info',
  completed: 'success',
  cancelled: 'danger',
};

export function SaleStatusBadge({ status }: { status: SaleStatus | string }) {
  return (
    <Badge variant={variantByStatus[status as SaleStatus] ?? 'default'}>
      {labelFor.saleStatus(status)}
    </Badge>
  );
}
