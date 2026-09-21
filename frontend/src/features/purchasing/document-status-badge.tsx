import { Badge } from '@/components/ui/badge';
import { labelFor } from '@/utils/format';

type BadgeVariant =
  | 'default'
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'outline';

/**
 * Maps a purchasing document status onto a badge colour.
 *
 * Neutral or terminal states (draft, closed, cancelled) sit in grey/red so the
 * states that need attention (submitted, partially received) stand out.
 */
const variantByStatus: Record<string, BadgeVariant> = {
  draft: 'default',
  submitted: 'info',
  counting: 'warning',
  review: 'info',
  approved: 'success',
  sent: 'info',
  partially_received: 'warning',
  received: 'success',
  completed: 'success',
  posted: 'success',
  converted: 'success',
  rejected: 'danger',
  cancelled: 'danger',
  closed: 'default',
};

export function DocumentStatusBadge({
  status,
}: {
  status: string | null | undefined;
}) {
  if (!status) {
    return <Badge variant="outline">-</Badge>;
  }

  return (
    <Badge variant={variantByStatus[status] ?? 'default'}>
      {labelFor.documentStatus(status)}
    </Badge>
  );
}
