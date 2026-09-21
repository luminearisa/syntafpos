import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { productApi } from '@/api/services';
import type { ProductLookupResult } from '@/types';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { formatMoneyString } from '@/utils/format';
import { Input } from '@/components/ui/input';

interface ProductPickerProps {
  /** Called with the product the user picked. */
  onSelect: (product: ProductLookupResult) => void;
  disabled?: boolean;
  /** Bounded so the drawer's own loading state can suppress interaction. */
  placeholder?: string;
}

/**
 * Debounced product search with a dropdown of results.
 *
 * Uses the /products/lookup endpoint, which returns the purchasable units and
 * prices a buyer needs to open a line.
 */
export function ProductPicker({
  onSelect,
  disabled,
  placeholder = 'Search products by name or SKU...',
}: ProductPickerProps) {
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const debouncedQuery = useDebouncedValue(query, 300);
  const trimmed = debouncedQuery.trim();

  const { data, isFetching } = useQuery({
    queryKey: ['products', 'purchasing-lookup', trimmed],
    queryFn: () => productApi.lookup({ search: trimmed, per_page: 20 }),
    enabled: open && trimmed.length >= 1,
  });

  const results = data?.data ?? [];

  const handleSelect = (product: ProductLookupResult) => {
    onSelect(product);
    setQuery('');
    setOpen(false);
  };

  return (
    <div className="relative">
      <Input
        name="product_search"
        icon="search-outline"
        placeholder={placeholder}
        value={query}
        onChange={(event) => {
          setQuery(event.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        disabled={disabled}
        className="h-9"
        aria-label="Search products"
      />

      {open && (
        <>
          {/* Click-away layer: a plain invisible button keeps the dropdown
              dismissible without a portal or an outside-click hook. */}
          <button
            type="button"
            aria-hidden="true"
            tabIndex={-1}
            className="fixed inset-0 z-10 cursor-default"
            onClick={() => setOpen(false)}
          />

          <div className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-surface shadow-lg">
            {isFetching ? (
              <div className="px-3 py-2 text-xs text-text-muted">Searching...</div>
            ) : results.length === 0 ? (
              <div className="px-3 py-2 text-xs text-text-muted">
                {trimmed
                  ? 'No products match your search.'
                  : 'Type a product name or SKU to search.'}
              </div>
            ) : (
              results.map((product) => (
                <button
                  key={`${product.id}-${product.product_variant_id ?? 'base'}`}
                  type="button"
                  onClick={() => handleSelect(product)}
                  className="flex w-full items-center justify-between gap-3 border-b border-border px-3 py-2 text-left text-sm transition-colors last:border-0 hover:bg-surface-alt"
                >
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-text">
                      {product.name}
                    </span>
                    <span className="font-mono text-xs text-text-subtle">
                      {product.sku}
                      {product.unit_code ? ` · ${product.unit_code}` : ''}
                    </span>
                  </span>

                  <span className="shrink-0 text-xs text-text-muted">
                    {formatMoneyString(product.cost_price)}
                  </span>
                </button>
              ))
            )}
          </div>
        </>
      )}
    </div>
  );
}
