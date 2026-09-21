import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { categoryApi, posApi } from '@/api/services';
import type { PosProduct } from '@/types';
import { useAuthStore } from '@/stores/auth-store';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { cn, formatDecimal, formatMoneyString } from '@/utils/format';
import { Badge } from '@/components/ui/badge';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/state';
import { IconButton } from '@/components/ui/overlay';

interface ProductGridProps {
  /** The shared search term, owned by the till so the shortcut can focus the box. */
  term: string;
  onAdd: (product: PosProduct) => void;
  disabled?: boolean;
}

/**
 * The left half of the till: a category strip over a grid of sellable products.
 *
 * Paged at 24 per request, and the request is debounced from the search box in
 * the header, so a cashier typing fast costs a handful of queries rather than
 * one per keystroke. Prices and stock arrive already resolved for the customer
 * on the cart and the register's own warehouse — the grid never computes money.
 */
export function ProductGrid({ term, onAdd, disabled }: ProductGridProps) {
  const companyId = useAuthStore((state) => state.scope.companyId);
  const [categoryId, setCategoryId] = useState<number | undefined>(undefined);
  const [page, setPage] = useState(1);

  const debounced = useDebouncedValue(term.trim(), 250);

  // Typing or filtering is a new search, not a later page of the old one, so
  // the cursor goes back to the first page of the new result set.
  useEffect(() => {
    setPage(1);
  }, [debounced, categoryId]);

  const { data, isFetching, isError, refetch } = useQuery({
    queryKey: ['pos', 'products', companyId, debounced, categoryId ?? null, page],
    queryFn: () =>
      posApi.searchProducts({
        search: debounced || undefined,
        category_id: categoryId,
        per_page: 24,
        page,
      }),
  });

  const { data: categories } = useQuery({
    queryKey: ['pos', 'categories', companyId],
    queryFn: () => categoryApi.list({ company_id: companyId ?? undefined, per_page: 100 }),
    enabled: companyId !== null,
    staleTime: 5 * 60 * 1000,
  });

  const products = data?.data ?? [];
  const lastPage = Number((data?.meta as { last_page?: number } | undefined)?.last_page ?? 1);

  return (
    <section className="flex min-h-0 flex-1 flex-col" aria-label="Products">
      <div className="flex items-center gap-1 overflow-x-auto border-b border-border px-3 py-2">
        <CategoryChip
          label="All"
          active={categoryId === undefined}
          onSelect={() => {
            setCategoryId(undefined);
            setPage(1);
          }}
        />
        {(categories?.data ?? []).map((category) => (
          <CategoryChip
            key={category.id}
            label={category.name}
            active={categoryId === category.id}
            onSelect={() => {
              setCategoryId(category.id);
              setPage(1);
            }}
          />
        ))}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-3">
        {isError ? (
          <ErrorState message="The product list could not be loaded." onRetry={() => refetch()} />
        ) : isFetching && products.length === 0 ? (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }, (_, index) => (
              <Skeleton key={index} className="h-28" />
            ))}
          </div>
        ) : products.length === 0 ? (
          <EmptyState
            icon="barcode-outline"
            title="Nothing matches"
            description={
              debounced
                ? `No sellable product matches “${debounced}”. Scan a code or clear the search.`
                : 'This company has no sellable products yet.'
            }
          />
        ) : (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
            {products.map((product) => (
              <ProductTile
                key={product.product_id}
                product={product}
                disabled={disabled}
                onAdd={() => onAdd(product)}
              />
            ))}
          </div>
        )}

        {lastPage > 1 && (
          <div className="mt-3 flex items-center justify-center gap-2 text-xs text-text-muted">
            <IconButton
              icon="chevron-back-outline"
              label="Previous page"
              disabled={page <= 1}
              onClick={() => setPage((value) => Math.max(1, value - 1))}
            />
            <span>
              Page {page} of {lastPage}
            </span>
            <IconButton
              icon="chevron-forward-outline"
              label="Next page"
              disabled={page >= lastPage}
              onClick={() => setPage((value) => Math.min(lastPage, value + 1))}
            />
          </div>
        )}
      </div>
    </section>
  );
}

function CategoryChip({
  label,
  active,
  onSelect,
}: {
  label: string;
  active: boolean;
  onSelect: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        'shrink-0 rounded-full border px-3 py-1 text-xs transition-colors',
        active
          ? 'border-primary bg-primary text-white'
          : 'border-border bg-surface text-text-muted hover:bg-surface-alt'
      )}
    >
      {label}
    </button>
  );
}

function ProductTile({
  product,
  onAdd,
  disabled,
}: {
  product: PosProduct;
  onAdd: () => void;
  disabled?: boolean;
}) {
  // An untracked item has no shelf to run out of, so it must not read as
  // "0 left" — that is a different situation than being genuinely out.
  const available = Number(product.stock.available);
  const out = product.stock.tracked && available <= 0;

  return (
    <button
      type="button"
      onClick={onAdd}
      disabled={disabled}
      className={cn(
        'flex h-full flex-col justify-between gap-2 rounded-lg border border-border bg-surface p-3 text-left',
        'transition-shadow hover:border-primary hover:shadow-sm focus-visible:outline-2 focus-visible:outline-primary',
        'disabled:cursor-not-allowed disabled:opacity-60'
      )}
    >
      <div className="min-w-0">
        <span className="line-clamp-2 text-sm font-medium text-text">{product.name}</span>
        <span className="mt-0.5 block truncate font-mono text-[11px] text-text-subtle">
          {product.sku}
          {product.unit?.code ? ` · ${product.unit.code}` : ''}
        </span>
      </div>

      <div className="flex items-end justify-between gap-2">
        <span className="text-sm font-semibold text-text">
          {formatMoneyString(product.price)}
        </span>

        {product.stock.tracked ? (
          <Badge
            variant={out ? 'danger' : product.stock.low ? 'warning' : 'outline'}
            className="shrink-0"
          >
            {out ? 'Out' : `${formatDecimal(product.stock.available, 0)} left`}
          </Badge>
        ) : (
          <Badge variant="outline" className="shrink-0">
            Untracked
          </Badge>
        )}
      </div>
    </button>
  );
}
