import { useQuery } from '@tanstack/react-query';
import { paymentMethodApi } from '@/api/services';

export const AVAILABLE_METHODS_KEY = ['payment-methods', 'available'] as const;

/**
 * The ways this shop can be paid, as the till should offer them.
 *
 * Cached for a shift rather than refetched per sale: the list is configuration, it
 * changes when someone in the admin panel changes it, and a till that re-read it on
 * every checkout would be one slow request away from a cashier pressing Pay blind.
 *
 * The server answers with built-in defaults when a shop has configured nothing, so
 * there is no empty state to design here — a shop that has never opened the screen
 * can still take cash.
 */
export function useAvailablePaymentMethods(enabled = true) {
  return useQuery({
    queryKey: AVAILABLE_METHODS_KEY,
    queryFn: () => paymentMethodApi.available(),
    enabled,
    staleTime: 5 * 60 * 1000,
  });
}
