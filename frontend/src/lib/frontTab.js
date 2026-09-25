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
