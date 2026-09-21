import { useEffect, useState } from 'react';

/**
 * Returns a debounced copy of a rapidly-changing value so that expensive
 * downstream work (API requests, filtering) only runs after input settles.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const handle = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(handle);
  }, [value, delay]);

  return debounced;
}
