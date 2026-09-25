import { useCallback, useMemo } from 'react';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { mergeSearch, parseView, viewDefaults } from './viewState.js';

/**
 * A table view kept in the URL of the current route. Spread the result onto
 * a DataTable: `<DataTable {...useUrlView({ defaults })} … />`.
 *
 * Changes replace the history entry rather than push one, so typing a search
 * does not leave a trail of back-button stops.
 */
export function useUrlView({ prefix = '', defaults } = {}) {
  const search = useSearch({ strict: false });
  const navigate = useNavigate();

  // Callers pass `defaults` inline; its contents, not its identity, are what matter.
  const defaultsKey = JSON.stringify(defaults ?? {});
  const options = useMemo(() => ({ prefix, defaults: JSON.parse(defaultsKey) }), [prefix, defaultsKey]);

  const view = useMemo(() => parseView(search, options), [search, options]);
  const defaultView = useMemo(() => viewDefaults(options.defaults), [options]);

  const onViewChange = useCallback(
    (next) => navigate({ to: '.', search: (previous) => mergeSearch(previous, next, options), replace: true }),
    [navigate, options],
  );

  return { view, onViewChange, defaultView };
}
