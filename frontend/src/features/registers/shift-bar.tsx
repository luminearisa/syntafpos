import { useState } from 'react';
import type { RegisterSession } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { formatMoneyString } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/state';
import { EXPECTED_CASH_FORMULA, formatDuration } from './shift-model';
import { CloseShiftDialog } from './close-shift-dialog';
import { CashMovementDialog } from './cash-movement-dialog';
import { OpenShiftDialog } from './open-shift-dialog';

type Dialog = 'open' | 'in' | 'out' | 'close' | null;

/**
 * The shift bar on the till (Phase 3.4).
 *
 * One strip that answers the three questions a cashier asks of a drawer: is it
 * open, whose is it, and how much cash should be in it right now. The expected
 * figure is the server's — the same `summarise()` the close and the report read —
 * so the number on screen and the number the count is measured against cannot
 * disagree.
 *
 * When nothing is open the bar says so and offers Open, unless the shop has not
 * switched the requirement on: selling without a shift is a legitimate state, so
 * this is a prompt rather than a wall.
 */
export function ShiftBar({
  shift,
  loading,
  registerMissing,
}: {
  shift: RegisterSession | null;
  loading: boolean;
  registerMissing: boolean;
}) {
  const can = useAuthStore((state) => state.can);
  const [dialog, setDialog] = useState<Dialog>(null);

  const mayOpen = can('register_sessions.open');
  const mayProcess = can('register_sessions.process');
  const mayClose = can('register_sessions.close');

  if (loading && !shift) {
    return <LoadingState label="Checking the drawer..." className="py-1" />;
  }

  if (!shift) {
    return (
      <>
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2">
          <Badge variant={registerMissing ? 'warning' : 'outline'} icon="ellipse-outline">
            Register closed
          </Badge>

          {registerMissing ? (
            <span className="text-xs text-text-muted">
              Pick a register in the top bar before opening a drawer.
            </span>
          ) : (
            <span className="text-xs text-text-muted">
              No shift is open on this till, so its takings will not be attributed to one.
            </span>
          )}

          {mayOpen && !registerMissing && (
            <Button
              size="sm"
              variant="primary"
              icon="lock-open-outline"
              className="ml-auto"
              onClick={() => setDialog('open')}
            >
              Open register
            </Button>
          )}
        </div>

        <OpenShiftDialog open={dialog === 'open'} onClose={() => setDialog(null)} />
      </>
    );
  }

  const summary = shift.summary;

  return (
    <>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-border bg-surface px-3 py-2">
        <Badge variant="success" icon="checkmark-circle">
          {shift.register_code ?? 'Register'} open
        </Badge>

        <span className="font-mono text-xs text-text-muted">{shift.number}</span>

        <span className="text-xs text-text-muted">
          {shift.cashier?.name ?? 'Cashier'} · {formatDuration(shift.duration_minutes)}
        </span>

        {summary && (
          <div className="flex items-baseline gap-1.5" title={EXPECTED_CASH_FORMULA}>
            <span className="text-[10px] tracking-wide text-text-subtle uppercase">
              Expected cash
            </span>
            <span className="font-mono text-base font-semibold tabular-nums text-text">
              {formatMoneyString(summary.expected_cash)}
            </span>
          </div>
        )}

        {shift.awaiting_approval && (
          <Badge variant="warning" icon="alert-circle-outline">
            Variance awaiting approval
          </Badge>
        )}

        <div className="ml-auto flex items-center gap-2">
          {mayProcess && (
            <>
              <Button
                size="sm"
                variant="secondary"
                icon="add-circle-outline"
                onClick={() => setDialog('in')}
                title="Put money into the drawer with a reason"
              >
                Cash in
              </Button>
              <Button
                size="sm"
                variant="secondary"
                icon="remove-circle-outline"
                onClick={() => setDialog('out')}
                title="Take money out of the drawer with a reason"
              >
                Cash out
              </Button>
            </>
          )}

          {mayClose && (
            <Button
              size="sm"
              variant="outline"
              icon="lock-closed-outline"
              onClick={() => setDialog('close')}
              title="Count the drawer and close the shift"
            >
              Close register
            </Button>
          )}
        </div>
      </div>

      <OpenShiftDialog open={dialog === 'open'} onClose={() => setDialog(null)} />
      <CashMovementDialog
        open={dialog === 'in' || dialog === 'out'}
        side={dialog === 'out' ? 'out' : 'in'}
        shift={shift}
        onClose={() => setDialog(null)}
      />
      <CloseShiftDialog open={dialog === 'close'} shift={shift} onClose={() => setDialog(null)} />
    </>
  );
}
