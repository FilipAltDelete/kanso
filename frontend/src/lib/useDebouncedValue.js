import { useEffect, useState } from 'react';

/** The value, once it has stopped changing for `delay` ms: a search box asks the API once per pause, not per key. */
export function useDebouncedValue(value, delay = 250) {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
