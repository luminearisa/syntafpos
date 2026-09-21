import { useCallback, useMemo } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { posApi } from '@/api/services';
import type { AddCartLinePayload, PosCart, PosCartItem } from '@/types';
import { useToast } from '@/components/ui/toast';
import { apiErrorMessage } from '@/utils/api-error';

export const CART_QUERY_KEY = ['pos', 'cart'] as const;
export const HELD_QUERY_KEY = ['pos', 'cart', 'held'] as const;

/**
 * Quantity steps in fixed-point, not float arithmetic.
 *
 * A till adds one unit at a time thousands of times a day, and "0.1 + 0.2" is
 * how a quantity starts disagreeing with the stock ledger. The column is
 * DECIMAL(20,6), so scaling to an integer count of millionths is exact for
 * every quantity the backend will accept.
 */
export function stepQuantity(current: string, delta: number, scale = 6): string {
  const factor = 10 ** scale;
  const units = Math.round(Number(current || 0) * factor) + Math.round(delta * factor);

  if (units <= 0) {
    return '0';
  }

  // Rendered from the integer count of millionths rather than divided, so no
  // float ever touches the number the cashier just typed.
  const text = String(units).padStart(scale + 1, '0');
  const whole = text.slice(0, -scale) || '0';
  const fraction = text.slice(-scale).replace(/0+$/, '');

  return fraction ? `${whole}.${fraction}` : whole;
}

/**
 * The cart the cashier is working, with the server as its only source of truth.
 *
 * Every mutation response carries the whole recomputed cart, so the cache is
 * written from that answer rather than being adjusted locally. The screen a
 * cashier reads and the total checkout will charge are then the same figure by
 * construction — an optimistic total here would be a second opinion nobody
 * asked for, and one that could be wrong.
 */
export function usePosCart(enabled = true) {
  const client = useQueryClient();
  const { toast } = useToast();

  const query = useQuery({
    queryKey: CART_QUERY_KEY,
    queryFn: () => posApi.current(),
    enabled,
    // The till is the one screen where a stale cart is worse than a spinner:
    // refetch whenever it regains focus, e.g. after a scanner app switch.
    refetchOnWindowFocus: true,
    staleTime: 0,
  });

  const cart = query.data?.data ?? null;

  const write = useCallback(
    (next: PosCart) => {
      client.setQueryData(CART_QUERY_KEY, { data: next, success: true, message: '' });

      return next;
    },
    [client]
  );

  /**
   * A scan is the common path and must feel instant: the search endpoint has
   * already resolved the code, so the add payload is handed straight over.
   */
  const addLine = useMutation({
    mutationFn: (payload: AddCartLinePayload) => {
      if (!cart) {
        throw new Error('The till is not open yet.');
      }

      return posApi.addItem(cart.id, payload);
    },
    onSuccess: (response) => write(response.data.cart),
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot add that item', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  const patchLine = useMutation({
    mutationFn: ({ itemId, data }: { itemId: number; data: Record<string, unknown> }) => {
      if (!cart) {
        throw new Error('The till is not open yet.');
      }

      return posApi.updateItem(cart.id, itemId, data);
    },
    onSuccess: (response) => write(response.data.cart),
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot change that line', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  const removeLine = useMutation({
    mutationFn: (itemId: number) => posApi.removeItem(cart!.id, itemId),
    onSuccess: (response) => write(response.data),
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot remove that line', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  const clear = useMutation({
    mutationFn: () => posApi.clear(cart!.id),
    onSuccess: (response) => write(response.data),
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot clear the cart', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  /** Header writes: customer, cart discount, note, label. */
  const patchCart = useMutation({
    mutationFn: (data: Record<string, unknown>) => posApi.update(cart!.id, data),
    onSuccess: (response) => write(response.data),
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot update the cart', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  /**
   * A label is saved on the cart before the hold flips its status, because the
   * hold endpoint takes no input — the queue row reads the label off the cart.
   *
   * The hold answer is the parked cart, not a working one: the server frees the
   * till by moving the only active cart out of that state. So the cart cache is
   * refetched (which re-opens an empty working cart) rather than written.
   */
  const hold = useMutation({
    mutationFn: async (label: string | null) => {
      if (label !== null) {
        await posApi.update(cart!.id, { label });
      }

      return posApi.hold(cart!.id);
    },
    onSuccess: (response) => {
      client.invalidateQueries({ queryKey: CART_QUERY_KEY });
      client.invalidateQueries({ queryKey: HELD_QUERY_KEY });
      toast({
        variant: 'success',
        title: 'Cart on hold',
        message: `Recall it with code ${response.data.number}.`,
      });
    },
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot hold this cart', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  const recall = useMutation({
    mutationFn: (number: string) => posApi.recall(number),
    onSuccess: (response) => {
      write(response.data);
      client.invalidateQueries({ queryKey: HELD_QUERY_KEY });
      toast({ variant: 'success', title: 'Cart recalled' });
    },
    onError: (error) => {
      toast({ variant: 'error', title: 'Cannot recall that cart', message: apiErrorMessage(error, 'Please try again.') });
    },
  });

  const lines = useMemo<PosCartItem[]>(() => cart?.items ?? [], [cart]);

  return {
    cart,
    lines,
    isLoading: query.isPending,
    isError: query.isError,
    refetch: query.refetch,
    addLine,
    patchLine,
    removeLine,
    clear,
    patchCart,
    hold,
    recall,
    busy:
      addLine.isPending ||
      patchLine.isPending ||
      patchCart.isPending ||
      hold.isPending ||
      recall.isPending,
  };
}

/** The parked carts, oldest first — the list behind the recall dialog. */
export function useHeldCarts(enabled: boolean) {
  return useQuery({
    queryKey: HELD_QUERY_KEY,
    queryFn: () => posApi.held(),
    enabled,
  });
}
