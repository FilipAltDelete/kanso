import { createContext, useContext } from 'react';

/**
 * Whether a page is in the tab in front of the pane last used (app/workspace).
 * Pages in other tabs stay mounted, so this is what keeps their keyboard
 * shortcuts from firing. Outside the workspace — a test, a page rendered on
 * its own — every page is in front.
 */
const FrontTabContext = createContext(true);

export const FrontTabProvider = FrontTabContext.Provider;

export function useIsFrontTab() {
  return useContext(FrontTabContext);
}

/**
 * Whether a page's tab is the one its pane shows, in front or not: with the
 * screen split, several are. A page in a tab behind another is still mounted
 * but cannot be seen, so it has no reason to refresh itself (lib/liveQuery.js).
 */
const ShownTabContext = createContext(true);

export const ShownTabProvider = ShownTabContext.Provider;

export function useIsTabShown() {
  return useContext(ShownTabContext);
}
