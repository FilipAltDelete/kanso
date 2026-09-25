/**
 * The workspace: which pages are open as tabs, in which pane, and how the
 * screen is split between the panes (as in Pimsen).
 *
 * A tab is nothing but the address of a page — `/orders?f.status=packed` —
 * because every page already keeps its whole state in its URL. That is what
 * makes a tab cheap to restore after a reload and safe to move between panes.
 *
 * The panes are the leaves of a split tree: a split divides its box between
 * two children side by side (`row`) or one above the other (`column`), and
 * either child can be split again — the layout an editor's split view has.
 *
 * Pure, so the rules (which tab becomes active when one closes, when a pane
 * disappears, what a split leaves behind) are tested without rendering
 * anything. Ids come in on the action, never from inside, for the same reason.
 */

/** What an empty workspace shows: closing the last tab opens it. */
export const HOME = '/';

/** Enough to compare four lists side by side; more is a pane too small to read. */
export const MAX_PANES = 4;

const MIN_RATIO = 0.15;
const MAX_RATIO = 0.85;

export const SIDES = ['left', 'right', 'top', 'bottom'];

export function emptyWorkspace() {
  return {
    tabs: {},
    panes: { main: { id: 'main', tabs: [], active: null } },
    layout: { type: 'pane', id: 'main' },
    focused: 'main',
  };
}

export function pathOf(href) {
  return href.split(/[?#]/)[0];
}

/** The panes in reading order: left to right, top to bottom. */
export function paneIds(node) {
  return node.type === 'pane' ? [node.id] : [...paneIds(node.a), ...paneIds(node.b)];
}

/** The page the person is looking at: the active tab of the pane they last used. */
export function activeTab(state) {
  const pane = state.panes[state.focused] ?? state.panes[paneIds(state.layout)[0]];
  return pane?.active ? state.tabs[pane.active] : null;
}

/**
 * Where each pane and each divider sits, as fractions of the workspace — so
 * the view can place every pane absolutely, in one flat list, and a pane that
 * gets split or moved is never torn down and rebuilt with the pages in it.
 */
export function layoutBoxes(node, box = { x: 0, y: 0, w: 1, h: 1 }, out = { panes: {}, splits: [] }) {
  if (node.type === 'pane') {
    out.panes[node.id] = box;
    return out;
  }

  const row = node.direction === 'row';
  const first = row ? { ...box, w: box.w * node.ratio } : { ...box, h: box.h * node.ratio };
  const second = row
    ? { ...box, x: box.x + first.w, w: box.w - first.w }
    : { ...box, y: box.y + first.h, h: box.h - first.h };

  out.splits.push({ id: node.id, direction: node.direction, ratio: node.ratio, box });
  layoutBoxes(node.a, first, out);
  layoutBoxes(node.b, second, out);

  return out;
}

export function workspaceReducer(state, action) {
  switch (action.type) {
    case 'open':
      return open(state, action);
    case 'navigate': {
      const tab = state.tabs[action.tab];
      if (!tab || tab.href === action.href) return state;
      return { ...state, tabs: { ...state.tabs, [action.tab]: { ...tab, href: action.href } } };
    }
    case 'activate':
      return activate(state, action.pane, action.tab);
    case 'focus':
      return state.focused === action.pane || !state.panes[action.pane] ? state : { ...state, focused: action.pane };
    case 'close':
      return close(state, action.tab);
    case 'move':
      return action.side ? splitOff(state, action) : move(state, action);
    case 'place':
      return place(state, action);
    case 'resize':
      return {
        ...state,
        layout: mapSplit(state.layout, action.split, (split) => ({
          ...split,
          ratio: Math.min(Math.max(action.ratio, MIN_RATIO), MAX_RATIO),
        })),
      };
    default:
      return state;
  }
}

/**
 * Open `href`. Unless a new tab is asked for, a tab already showing it is
 * brought forward instead — `match: 'path'` for the menu, where the Orders
 * tab is the Orders tab whatever it is filtered on, and `match: 'href'` for
 * an address typed or shared, whose filters are the point.
 */
function open(state, { href, id, newTab = false, background = false, match = 'path' }) {
  if (!newTab) {
    const existing =
      findTab(state, (tab) => tab.href === href) ?? (match === 'path' ? findTab(state, (tab) => pathOf(tab.href) === pathOf(href)) : null);
    if (existing) return activate(state, existing.pane, existing.tab);
  }

  const pane = state.panes[state.focused] ?? state.panes[paneIds(state.layout)[0]];
  const after = pane.tabs.indexOf(pane.active);
  const tabs = [...pane.tabs];
  tabs.splice(after === -1 ? tabs.length : after + 1, 0, id);

  return {
    ...state,
    tabs: { ...state.tabs, [id]: { id, href } },
    panes: { ...state.panes, [pane.id]: { ...pane, tabs, active: background && pane.active ? pane.active : id } },
    focused: pane.id,
  };
}

/**
 * Open `href` as a new tab exactly where it was dropped — a page dragged in
 * from the menu: at `index` in a pane's tab bar, in the middle of a pane, or
 * in a new pane on one `side` of it. Always a new tab; placing it was the
 * point. With no room for another pane it lands in the pane it was dropped on.
 */
function place(state, { href, id, pane: targetId, index, side, newPane, newSplit }) {
  const pane = state.panes[targetId];
  if (!pane) return state;

  const tabs = [...pane.tabs];
  tabs.splice(Math.min(Math.max(index ?? tabs.length, 0), tabs.length), 0, id);
  const placed = {
    ...state,
    tabs: { ...state.tabs, [id]: { id, href } },
    panes: { ...state.panes, [pane.id]: { ...pane, tabs, active: id } },
    focused: pane.id,
  };

  return side ? splitOff(placed, { tab: id, pane: pane.id, side, newPane, newSplit }) : placed;
}

function activate(state, paneId, tabId) {
  const pane = state.panes[paneId];
  if (!pane || !pane.tabs.includes(tabId)) return state;
  if (pane.active === tabId && state.focused === paneId) return state;

  return { ...state, panes: { ...state.panes, [paneId]: { ...pane, active: tabId } }, focused: paneId };
}

/**
 * Close a tab. Closing the last one opens the dashboard in its place: the
 * workspace is never left empty. Its id derives from the closed tab's, so the
 * reducer stays pure and the id is still unique.
 */
function close(state, tabId) {
  const pane = paneOfTab(state, tabId);
  if (!pane) return state;

  const tabs = { ...state.tabs };
  delete tabs[tabId];

  const closed = withoutTab({ ...state, tabs }, pane.id, tabId);
  if (Object.keys(closed.tabs).length > 0) return closed;

  return open(closed, { id: `home-${tabId}`, href: HOME, newTab: true });
}

/** Move a tab to `index` in the tab bar of `pane` — its own, or another. */
function move(state, { tab: tabId, pane: targetId, index }) {
  const source = paneOfTab(state, tabId);
  const target = state.panes[targetId];
  if (!source || !target) return state;

  if (target.id === source.id) {
    const from = source.tabs.indexOf(tabId);
    const tabs = source.tabs.filter((candidate) => candidate !== tabId);
    // `index` counts the tab being moved, which is no longer in the list.
    const at = index ?? tabs.length;
    const to = Math.min(Math.max(at > from ? at - 1 : at, 0), tabs.length);
    tabs.splice(to, 0, tabId);
    return { ...state, panes: { ...state.panes, [source.id]: { ...source, tabs, active: tabId } }, focused: source.id };
  }

  const left = withoutTab(state, source.id, tabId);
  const landing = left.panes[target.id];
  const tabs = [...landing.tabs];
  tabs.splice(Math.min(Math.max(index ?? tabs.length, 0), tabs.length), 0, tabId);

  return { ...left, panes: { ...left.panes, [landing.id]: { ...landing, tabs, active: tabId } }, focused: landing.id };
}

/**
 * Move a tab into a new pane on one `side` of `pane`, splitting that pane in
 * two. The tab's old pane closes if it leaves it empty — unless it is the pane
 * being split, which would split a pane into itself and nothing.
 */
function splitOff(state, { tab: tabId, pane: targetId, side, newPane, newSplit }) {
  const source = paneOfTab(state, tabId);
  if (!source || !state.panes[targetId] || !SIDES.includes(side)) return state;
  if (paneIds(state.layout).length >= MAX_PANES && source.tabs.length > 1) return state;
  if (source.id === targetId && source.tabs.length < 2) return state;

  const left = withoutTab(state, source.id, tabId);
  if (!left.panes[targetId]) return state;

  const before = side === 'left' || side === 'top';
  const layout = mapPane(left.layout, targetId, (leaf) => ({
    type: 'split',
    id: newSplit,
    direction: side === 'left' || side === 'right' ? 'row' : 'column',
    ratio: 0.5,
    a: before ? { type: 'pane', id: newPane } : leaf,
    b: before ? leaf : { type: 'pane', id: newPane },
  }));

  return {
    ...left,
    panes: { ...left.panes, [newPane]: { id: newPane, tabs: [tabId], active: tabId } },
    layout,
    focused: newPane,
  };
}

/**
 * `pane` without `tabId`. The tab to its right takes over, as in a browser; a
 * pane left empty closes and its neighbour takes its space, unless it is the
 * last one.
 */
function withoutTab(state, paneId, tabId) {
  const pane = state.panes[paneId];
  const at = pane.tabs.indexOf(tabId);
  const tabs = pane.tabs.filter((candidate) => candidate !== tabId);

  if (tabs.length === 0 && Object.keys(state.panes).length > 1) {
    const panes = { ...state.panes };
    delete panes[paneId];
    const layout = removePane(state.layout, paneId);
    return { ...state, panes, layout, focused: state.focused === paneId ? paneIds(layout)[0] : state.focused };
  }

  const active = pane.active === tabId ? (tabs[at] ?? tabs[at - 1] ?? null) : pane.active;
  return { ...state, panes: { ...state.panes, [paneId]: { ...pane, tabs, active } } };
}

function findTab(state, predicate) {
  // The focused pane first: a page open in two should come forward where the
  // person is already looking.
  const order = paneIds(state.layout).sort((a, b) => (b === state.focused) - (a === state.focused));

  for (const paneId of order) {
    const tab = state.panes[paneId].tabs.find((candidate) => predicate(state.tabs[candidate]));
    if (tab) return { pane: paneId, tab };
  }

  return null;
}

function paneOfTab(state, tabId) {
  return Object.values(state.panes).find((pane) => pane.tabs.includes(tabId)) ?? null;
}

function mapPane(node, paneId, fn) {
  if (node.type === 'pane') return node.id === paneId ? fn(node) : node;
  return { ...node, a: mapPane(node.a, paneId, fn), b: mapPane(node.b, paneId, fn) };
}

function mapSplit(node, splitId, fn) {
  if (node.type === 'pane') return node;
  const mapped = { ...node, a: mapSplit(node.a, splitId, fn), b: mapSplit(node.b, splitId, fn) };
  return node.id === splitId ? fn(mapped) : mapped;
}

/** The tree without `paneId`; its sibling takes the whole of the split they shared. */
function removePane(node, paneId) {
  if (node.type === 'pane') return node.id === paneId ? null : node;

  const a = removePane(node.a, paneId);
  const b = removePane(node.b, paneId);
  if (a === null) return b;
  if (b === null) return a;
  return { ...node, a, b };
}

/**
 * A stored workspace, or null when it is missing or not one: whatever is in
 * localStorage was written by some earlier build, or by hand.
 */
export function restoreWorkspace(raw) {
  if (!raw || typeof raw !== 'object' || !raw.tabs || typeof raw.tabs !== 'object') return null;
  if (!raw.panes || typeof raw.panes !== 'object' || Array.isArray(raw.panes)) return null;

  const tabs = {};
  for (const [id, tab] of Object.entries(raw.tabs)) {
    if (tab && typeof tab.href === 'string' && tab.href.startsWith('/')) tabs[id] = { id, href: tab.href };
  }

  const seen = new Set();
  const panes = {};
  for (const pane of Object.values(raw.panes)) {
    if (!pane || typeof pane.id !== 'string' || !Array.isArray(pane.tabs) || panes[pane.id]) continue;
    const kept = pane.tabs.filter((id) => tabs[id] && !seen.has(id) && seen.add(id));
    panes[pane.id] = { id: pane.id, tabs: kept, active: kept.includes(pane.active) ? pane.active : (kept[0] ?? null) };
  }

  const placed = new Set();
  // A pane survives if it holds a tab; an empty one only as the whole layout.
  const keep = (id) => panes[id] && panes[id].tabs.length > 0;
  let layout = validLayout(raw.layout, keep, placed);

  if (layout === null) {
    const first = Object.keys(panes)[0];
    if (first === undefined) return null;
    placed.add(first);
    layout = { type: 'pane', id: first };
  }

  if (placed.size > MAX_PANES) return null;

  for (const id of Object.keys(panes)) if (!placed.has(id)) delete panes[id];
  const shown = new Set(Object.values(panes).flatMap((pane) => pane.tabs));
  for (const id of Object.keys(tabs)) if (!shown.has(id)) delete tabs[id];

  const focused = placed.has(raw.focused) ? raw.focused : paneIds(layout)[0];

  return { tabs, panes, layout, focused };
}

function validLayout(node, keep, placed) {
  if (!node || typeof node !== 'object') return null;

  if (node.type === 'pane') {
    if (typeof node.id !== 'string' || !keep(node.id) || placed.has(node.id)) return null;
    placed.add(node.id);
    return { type: 'pane', id: node.id };
  }

  if (node.type !== 'split' || typeof node.id !== 'string' || !['row', 'column'].includes(node.direction)) return null;

  const a = validLayout(node.a, keep, placed);
  const b = validLayout(node.b, keep, placed);
  if (a === null) return b;
  if (b === null) return a;

  const ratio = typeof node.ratio === 'number' ? Math.min(Math.max(node.ratio, MIN_RATIO), MAX_RATIO) : 0.5;
  return { type: 'split', id: node.id, direction: node.direction, ratio, a, b };
}
