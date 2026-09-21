import { useEffect, useState } from 'react';
import { userApi } from '@/api/services';
import type { User } from '@/types';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/auth-store';
import { formatMoneyString } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Modal } from '@/components/ui/overlay';
import { LoadingState } from '@/components/ui/state';
import { localDateTimeValue } from './shift-model';
import { useOpenShift, useShiftRegisters } from './use-shift';

/** Floats a shop is likely to start a drawer with. A suggestion, never a default. */
const FLOAT_PRESETS = ['100000', '200000', '500000'];

/**
 * Open a register with float (Phase 3.4).
 *
 * The four inputs the work order names, in the order they are actually decided:
 * which drawer, whose it is, how much float went in, and when it started. The
 * register list carries the shift currently open on each one, so a drawer already
 * working is shown as working rather than offered as a blank form the server will
 * refuse.
 *
 * Cashier defaults to the signed-in user — that is the overwhelmingly common case —
 * but stays editable, because a shift must be attributable to the person standing
 * at the till, not to whoever happened to be logged in.
 */
export function OpenShiftDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const scope = useAuthStore((state) => state.scope);
  const user = useAuthStore((state) => state.user);
  const can = useAuthStore((state) => state.can);
  const registers = useShiftRegisters(open);
  const openShift = useOpenShift(() => onClose());

  const [registerId, setRegisterId] = useState<number | ''>(scope.registerId ?? '');
  const [cashierId, setCashierId] = useState<string>(String(user?.id ?? ''));
  const [float, setFloat] = useState('');
  const [openedAt, setOpenedAt] = useState(() => localDateTimeValue());
  const [notes, setNotes] = useState('');

  const mayListUsers = can('users.view');

  const cashiers = useQuery({
    queryKey: ['users', 'cashiers', scope.companyId],
    queryFn: () => userApi.list({ per_page: 100, company_id: scope.companyId ?? undefined }),
    enabled: open && mayListUsers,
  });

  // A drawer someone else is already working is not a choice to offer twice.
  const options = (registers.data?.data ?? []).map((register) => ({
    label: register.open_session
      ? `${register.code} · ${register.name} — open on ${register.open_session.number}`
      : `${register.code} · ${register.name}`,
    value: register.id,
  }));

  const busyRegisters = new Set(
    (registers.data?.data ?? []).filter((register) => register.open_session).map((register) => register.id)
  );

  // Whatever the topbar was pointed at when this dialog opened wins the default.
  useEffect(() => {
    if (open) {
      setRegisterId(scope.registerId ?? '');
      setFloat('');
      setNotes('');
      setOpenedAt(localDateTimeValue());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const chosen =
    typeof registerId === 'number'
      ? (registers.data?.data ?? []).find((register) => register.id === registerId)
      : undefined;

  const opening = float.trim() === '' ? null : float;
  const ready =
    typeof registerId === 'number' &&
    opening !== null &&
    Number(opening) > 0 &&
    !busyRegisters.has(registerId);

  const submit = () => {
    openShift.mutate({
      register_id: Number(registerId),
      cashier_id: cashierId === '' ? undefined : Number(cashierId),
      opening_balance: opening ?? '0',
      opened_at: openedAt === '' ? undefined : new Date(openedAt).toISOString(),
      notes: notes.trim() === '' ? null : notes.trim(),
    });
  };

  return (
    <Modal
      open={open}
      onClose={openShift.isPending ? () => undefined : onClose}
      title="Open register"
      description="Put the float in the drawer and start a shift. Every cash sale on this till from now on is counted against it."
      size="md"
      footer={
        <div className="flex w-full items-center gap-2">
          {opening !== null && Number(opening) > 0 && (
            <span className="text-xs text-text-muted">
              Float {formatMoneyString(opening)} · expected cash starts here
            </span>
          )}
          <div className="ml-auto flex gap-2">
            <Button variant="secondary" size="sm" onClick={onClose} disabled={openShift.isPending}>
              Cancel
            </Button>
            <Button
              variant="primary"
              size="sm"
              icon="lock-open-outline"
              loading={openShift.isPending}
              disabled={!ready}
              onClick={submit}
            >
              Open register
            </Button>
          </div>
        </div>
      }
    >
      {registers.isLoading ? (
        <LoadingState label="Reading the drawers..." />
      ) : options.length === 0 ? (
        <p className="text-sm text-text-muted">
          This company has no active register yet. Add one under Registers before opening a shift.
        </p>
      ) : (
        <div className="flex flex-col gap-3">
          <Select
            label="Register"
            name="shift_register"
            options={options}
            value={registerId === '' ? '' : String(registerId)}
            onChange={(event) =>
              setRegisterId(event.target.value === '' ? '' : Number(event.target.value))
            }
            error={
              chosen && busyRegisters.has(chosen.id)
                ? `${chosen.code} already has ${chosen.open_session?.number} open on it. Close that shift first.`
                : undefined
            }
          />

          <Select
            label="Cashier"
            name="shift_cashier"
            placeholder={mayListUsers ? 'Me (default)' : 'Signed-in user'}
            disabled={!mayListUsers}
            options={
              mayListUsers
                ? (cashiers.data?.data ?? []).map((entry: User) => ({
                    label: entry.name,
                    value: entry.id,
                  }))
                : [{ label: user?.name ?? 'Signed-in user', value: user?.id ?? '' }]
            }
            value={cashierId}
            onChange={(event) => setCashierId(event.target.value)}
          />
          {!mayListUsers && (
            <p className="-mt-2 text-xs text-text-subtle">
              You cannot list staff, so the shift opens in your own name.
            </p>
          )}

          <div className="flex flex-col gap-1.5">
            <Input
              type="number"
              inputMode="decimal"
              min="0"
              step="any"
              label="Opening cash (float)"
              name="shift_opening_balance"
              autoComplete="off"
              placeholder="0"
              value={float}
              onChange={(event) => setFloat(event.target.value)}
              error={
                opening !== null && Number(opening) <= 0
                  ? 'A register opened with no float cannot be reconciled.'
                  : undefined
              }
            />
            <div className="flex flex-wrap gap-1.5">
              {FLOAT_PRESETS.map((preset) => (
                <Button
                  key={preset}
                  size="xs"
                  variant="ghost"
                  className="border border-border"
                  onClick={() => setFloat(preset)}
                >
                  {formatMoneyString(preset)}
                </Button>
              ))}
            </div>
          </div>

          <Input
            type="datetime-local"
            label="Date and time opened"
            name="shift_opened_at"
            value={openedAt}
            onChange={(event) => setOpenedAt(event.target.value)}
          />

          <Textarea
            label="Note"
            name="shift_notes"
            placeholder="Handover notes — who was told, what the drawer was short of…"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />

          {chosen && !chosen.open_session && (
            <Badge variant="outline" icon="information-circle-outline">
              {chosen.branch_name ?? 'This branch'} · {chosen.name}
            </Badge>
          )}
        </div>
      )}
    </Modal>
  );
}
