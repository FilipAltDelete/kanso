import { useEffect, useRef, useSyncExternalStore } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useIsTabShown } from './frontTab.js';

/** How often a live page asks for its data again; the dashboard polls as often (api/dashboard.js). */
export const LIVE_REFRESH_MS = 30_000;

const EVERYTHING = '*';

/** Open holds by query key (as JSON), EVERYTHING for all of them; a count each, as holds nest. */
const holds = new Map();
const listeners = new Set();

function subscribe(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function change(key, by) {
  const count = (holds.get(key) ?? 0) + by;
  if (count > 0) holds.set(key, count);
  else holds.delete(key);
  for (const listener of listeners) listener();
}

/**
 * Keeps live queries from refreshing themselves while `active` (ADR-0020):
 * every one of them, or only those for `queryKey`.
 *
 * A form that saves with the version of the record it was opened on must not
 * have the record refreshed under it: the save would carry the new version,
 * and overwrite a change made elsewhere instead of being refused as stale
 * (409). Every modal dialog holds everything — nothing behind it can be used
 * while it is open — and a form on a page holds its own record. What a 409
 * invalidates is still fetched: that is how the form learns what changed.
 */
export function useHoldLiveRefresh({ queryKey = null, active = true } = {}) {
  const key = queryKey === null ? EVERYTHING : JSON.stringify(queryKey);

  useEffect(() => {
    if (!active) return undefined;
    change(key, 1);
    return () => change(key, -1);
  }, [key, active]);
}

function useHeld(queryKey) {
  const key = JSON.stringify(queryKey);

  return useSyncExternalStore(subscribe, () => holds.has(EVERYTHING) || holds.has(key));
}

/**
 * useQuery for data that changes under the person looking at it — orders
 * move through the warehouse all day. Refetched every LIVE_REFRESH_MS, when
 * the browser window comes back into focus and when the network does, while
 * its tab is shown (lib/frontTab.js) and nothing holds it. A tab that comes
 * back into view, or out from under a dialog, with data older than that
 * refreshes at once rather than at the next tick.
 */
export function useLiveQuery(options) {
  const shown = useIsTabShown();
  const held = useHeld(options.queryKey);
  const live = shown && !held;

  const query = useQuery({
    ...options,
    refetchInterval: live ? LIVE_REFRESH_MS : false,
    refetchOnWindowFocus: live,
    refetchOnReconnect: live,
  });

  const { refetch, dataUpdatedAt } = query;
  const wasLive = useRef(live);
  useEffect(() => {
    const woke = live && !wasLive.current;
    wasLive.current = live;
    // Not cancelling one already under way: that is the refresh wanted.
    if (woke && dataUpdatedAt > 0 && Date.now() - dataUpdatedAt >= LIVE_REFRESH_MS) refetch({ cancelRefetch: false });
  }, [live, refetch, dataUpdatedAt]);

  return query;
}
