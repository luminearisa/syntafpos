import { useCallback, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { ApiResponse, ListParams, PaginationMeta } from '@/types';

/**
 * Drives a paginated, searchable, sortable listing page.
 *
 * `params` is the source of truth for the request and is replaced (not merged)
 * on every change so React Query re-fetches on identity changes. Page-level
 * filters are spread into the initial params by the caller.
 *
 * A listing endpoint answers with the row array in the envelope's `data` and
 * the page cursor in its `meta`, so `rows` and `pagination` are read straight
 * off the envelope rather than from a nested page object.
 */
export function useListQuery<T>(
  queryKey: readonly unknown[],
  fetcher: (params: ListParams) => Promise<ApiResponse<T[]>>,
  initial: ListParams = {}
) {
  const [params, setParams] = useState<ListParams>({
    page: 1,
    per_page: 20,
    ...initial,
  });

  const query = useQuery({
    queryKey: [...queryKey, params],
    queryFn: () => fetcher(params),
    placeholderData: (previous) => previous,
  });

  const onParamsChange = useCallback((partial: Partial<ListParams>) => {
    setParams((current) => ({ ...current, ...partial }));
  }, []);

  const envelope = query.data;

  return {
    ...query,
    params,
    onParamsChange,
    rows: envelope?.data ?? [],
    pagination: (envelope?.meta as PaginationMeta | undefined) ?? null,
  };
}

/**
 * Invalidate a listing so it reflects a completed mutation.
 */
export function useInvalidateList() {
  const client = useQueryClient();

  return useCallback(
    (queryKey: readonly unknown[]) => {
      client.invalidateQueries({ queryKey });
    },
    [client]
  );
}
