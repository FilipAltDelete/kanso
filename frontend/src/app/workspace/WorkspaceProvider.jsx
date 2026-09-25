import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { activeTab, emptyWorkspace, restoreWorkspace, workspaceReducer } from './workspace.js';

const WorkspaceContext = createContext(null);

let sequence = 0;
export function newId(prefix) {
  sequence += 1;
  return `${prefix}-${Date.now().toString(36)}-${sequence.toString(36)}`;
}

function storageKey(userId) {
  return `kanso.workspace.${userId}`;
}

function addressBar() {
  return window.location.pathname + window.location.search + window.location.hash;
}

/**
 * The stored workspace, with the address the browser was opened on brought
 * forward — a link someone shared opens as a tab of its own, filters and all,
 * next to whatever was already open. A reload finds its own tab, because the
 * address bar always shows the tab in front.
 */
function boot(userId) {
  let stored = null;
  try {
    stored = restoreWorkspace(JSON.parse(window.localStorage.getItem(storageKey(userId)) ?? 'null'));
  } catch {
    // Unreadable or blocked storage: start from nothing.
  }

  return workspaceReducer(stored ?? emptyWorkspace(), { type: 'open', id: newId('tab'), href: addressBar(), match: 'href' });
}

/**
 * The open tabs and panes, per person and per browser (as in Pimsen).
 *
 * Kept in the browser: a layout preference, not data. Nothing on the server
 * reads it, and losing it costs the tabs, never an edit. Keyed by user so two
 * people sharing a browser do not open each other's pages.
 *
 * The browser's history is kept in step with the tab in front: a navigation in
 * it pushes an entry, and Back and Forward move that tab through its own
 * history. A tab that has nowhere to go back to stays where it is.
 */
export function WorkspaceProvider({ userId, children }) {
  const [state, dispatch] = useReducer(workspaceReducer, userId, boot);

  const latest = useRef(state);
  latest.current = state;
  const routers = useRef(new Map());
  const position = useRef(0);
  // What is being dragged over the workspace: `{ tab }` for one of its tabs,
  // `{ href }` for a page from the menu. Shared, because the menu starts a drag
  // the panes have to answer.
  const [dragging, setDragging] = useState(null);

  useEffect(() => {
    try {
      window.localStorage.setItem(storageKey(userId), JSON.stringify(state));
    } catch {
      // Blocked storage: the tabs last until the page is left.
    }
  }, [state, userId]);

  const current = activeTab(state);
  const href = current?.href ?? null;

  useEffect(() => {
    if (href !== null && href !== addressBar()) window.history.replaceState({ kanso: position.current }, '', href);
  }, [href]);

  useEffect(() => {
    window.history.replaceState({ kanso: position.current }, '', addressBar());

    function onPopState(event) {
      const to = event.state?.kanso;
      const tab = activeTab(latest.current);
      const router = tab ? routers.current.get(tab.id) : null;

      if (typeof to === 'number' && router) {
        if (to < position.current && router.history.canGoBack()) router.history.back();
        else if (to > position.current) router.history.forward();
        position.current = to;
      }

      // The address bar shows the tab in front, whatever the browser popped
      // to; a tab that did move corrects it again when its page updates.
      if (tab) window.history.replaceState({ kanso: position.current }, '', tab.href);
    }

    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  const open = useCallback((target, options = {}) => dispatch({ type: 'open', id: newId('tab'), href: target, ...options }), []);

  const navigated = useCallback((tabId, target, action) => {
    dispatch({ type: 'navigate', tab: tabId, href: target });

    if (action === 'PUSH' && activeTab(latest.current)?.id === tabId && target !== addressBar()) {
      position.current += 1;
      window.history.pushState({ kanso: position.current }, '', target);
    }
  }, []);

  const register = useCallback((tabId, router) => {
    if (router) routers.current.set(tabId, router);
    else routers.current.delete(tabId);
  }, []);

  const value = useMemo(
    () => ({ state, current, dispatch, open, navigated, register, dragging, setDragging }),
    [state, current, open, navigated, register, dragging],
  );

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

export function useWorkspace() {
  const workspace = useContext(WorkspaceContext);
  if (workspace === null) throw new Error('useWorkspace() outside <WorkspaceProvider>');
  return workspace;
}
