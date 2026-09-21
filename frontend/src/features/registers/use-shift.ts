import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { registerSessionApi, settingsApi } from '@/api/services';
import type {
  CashMovementInput,
  CloseRegisterPayload,
  ListParams,
  OpenRegisterPayload,
  RegisterSession,
} from '@/types';
import { listQueryKeys } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { apiErrorMessage } from '@/utils/api-error';
import { useToast } from '@/components/ui/toast';

/**
 * The shift a till is working (Phase 3.4).
 *
 * Everything here is keyed on the drawer rather than on the cashier, because a
 * shift belongs to a register: two people on the same till are reconciling the
 * same drawer, and a shift handed over mid-session is exactly the case the code
 * has to read correctly.
 *
 * `refetchOnWindowFocus` is on for the same reason the cart has it: the figures on
 * a shift bar are a running total, and money arrives on it from the checkout in
 * another tab, from a colleague's cash-out, or from a manager's approval. A bar
 * that can be an hour out is worse than one that briefly shows a spinner.
 */
export const SHIFT_QUERY_KEY = ['register-sessions', 'current'] as const;

export function useCurrentShift(enabled = true) {
  const registerId = useAuthStore((state) => state.scope.registerId);

  return useQuery({
    queryKey: [...SHIFT_QUERY_KEY, registerId],
    queryFn: () => registerSessionApi.current(),
    enabled,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

/** Every drawer the shop owns, with the shift open on it — the picker behind "Open register". */
export function useShiftRegisters(enabled = true) {
  return useQuery({
    queryKey: ['register-sessions', 'registers'],
    queryFn: () => registerSessionApi.registers(),
    enabled,
  });
}

/**
 * The shop's variance tolerance, for the close dialog's preview only.
 *
 * Reading it needs settings.view, which a cashier does not have — hence `enabled`
 * rather than a guess. What the till shows when it cannot read it is the difference
 * without a verdict, because the verdict is the server's at close: the shift answers
 * with `requires_approval`, and that is the one that counts.
 */
export function useVarianceThreshold(enabled = true) {
  return useQuery({
    queryKey: ['settings', 'registers.variance_threshold'],
    queryFn: () => settingsApi.show('registers.variance_threshold'),
    enabled,
    retry: 0,
    staleTime: 5 * 60 * 1000,
  });
}

/** One shift, with its movements and its live figures — the detail/report screen. */
export function useShift(id: number | null, enabled = true) {
  return useQuery({
    queryKey: ['register-sessions', id, 'detail'],
    queryFn: () => registerSessionApi.show(id as number),
    enabled: enabled && id !== null,
  });
}

export function useShiftMovements(id: number | null, params: ListParams = {}, enabled = true) {
  return useQuery({
    queryKey: ['register-sessions', id, 'movements', params],
    queryFn: () => registerSessionApi.movements(id as number, params),
    enabled: enabled && id !== null,
  });
}

export function useShiftReport(id: number | null, enabled = true) {
  return useQuery({
    queryKey: ['register-sessions', id, 'report'],
    queryFn: () => registerSessionApi.report(id as number),
    enabled: enabled && id !== null,
  });
}

/**
 * Refresh everything a shift's money touches.
 *
 * A count, a cash-out and an approval all move the same handful of screens: the
 * till's bar, the shift list a manager is reading, the shift detail, and — after a
 * close — the sale list, whose rows are what the cash was. Invalidating the parent
 * key covers the detail/movements/report variants without enumerating them.
 */
export function useInvalidateShift() {
  const client = useQueryClient();

  return () => {
    client.invalidateQueries({ queryKey: listQueryKeys.registerSessions });
    client.invalidateQueries({ queryKey: ['register-sessions'] });
    client.invalidateQueries({ queryKey: listQueryKeys.sales });
  };
}

export function useOpenShift(onDone?: (shift: RegisterSession) => void) {
  const { toast } = useToast();
  const refresh = useInvalidateShift();

  return useMutation({
    mutationFn: (payload: OpenRegisterPayload) => registerSessionApi.open(payload),
    onSuccess: (response) => {
      refresh();
      toast({ variant: 'success', title: 'Register open', message: response.message });
      onDone?.(response.data);
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Cannot open this register',
        message: apiErrorMessage(error, 'Nothing was started — check the drawer and the float.'),
      });
    },
  });
}

export function useCashMovement(shiftId: number | null) {
  const { toast } = useToast();
  const refresh = useInvalidateShift();

  return useMutation({
    mutationFn: (payload: CashMovementInput) => {
      if (shiftId === null) {
        throw new Error('No register is open on this till.');
      }

      return registerSessionApi.addMovement(shiftId, payload);
    },
    onSuccess: (response) => {
      refresh();
      toast({
        variant: 'success',
        title: response.data.direction === 'in' ? 'Cash in recorded' : 'Cash out recorded',
        message: `${response.data.type_label} — ${response.data.reason}`,
      });
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Cash movement refused',
        message: apiErrorMessage(error, 'Nothing was recorded in the drawer.'),
      });
    },
  });
}

export function useCloseShift(onDone?: (shift: RegisterSession) => void) {
  const { toast } = useToast();
  const refresh = useInvalidateShift();

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: CloseRegisterPayload }) =>
      registerSessionApi.close(id, payload),
    onSuccess: (response) => {
      refresh();

      const shift = response.data;

      toast({
        variant: shift.requires_approval ? 'warning' : 'success',
        title: `Shift ${shift.number} closed`,
        message: shift.requires_approval
          ? 'The variance is over this shop\'s tolerance, so a supervisor has to approve it.'
          : response.message,
      });

      onDone?.(shift);
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Cannot close the register',
        message: apiErrorMessage(error, 'The shift is still open and the drawer unchanged.'),
      });
    },
  });
}

export function useApproveVariance(onDone?: () => void) {
  const { toast } = useToast();
  const refresh = useInvalidateShift();

  return useMutation({
    mutationFn: ({ id, note }: { id: number; note: string | null }) =>
      registerSessionApi.approve(id, note),
    onSuccess: (response) => {
      refresh();
      toast({ variant: 'success', title: 'Variance approved', message: response.message });
      onDone?.();
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Approval refused',
        message: apiErrorMessage(error, 'The shift was left as it was counted.'),
      });
    },
  });
}

export function useReopenShift(onDone?: () => void) {
  const { toast } = useToast();
  const refresh = useInvalidateShift();

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      registerSessionApi.reopen(id, reason),
    onSuccess: (response) => {
      refresh();
      toast({ variant: 'warning', title: 'Shift reopened', message: response.message });
      onDone?.();
    },
    onError: (error) => {
      toast({
        variant: 'error',
        title: 'Cannot reopen this shift',
        message: apiErrorMessage(error, 'The count stands and the drawer stays shut.'),
      });
    },
  });
}
