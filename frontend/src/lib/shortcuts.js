import { isTyping } from '../components/ui/table/keyboard.js';

/**
 * Every keyboard shortcut in the app, in one list: the handlers are
 * registered by id where the work is (`useShortcuts`), and the help dialog
 * (`?`) is drawn from this list, so the two cannot drift apart.
 *
 *   keys     a sequence of keys, pressed one after the other ("g", then "o");
 *            a step with "+" is a chord ("Control+a"), shown in the help only
 *   group    the help dialog's section
 *   labelKey what it does, through t()
 *   builtIn  handled by a component itself (the table's grid keys); listed in
 *            the help, never matched here
 *
 * Single letters only, no modifiers: Ctrl, Alt and Cmd combinations belong
 * to the browser and to screen readers. None fires while typing in a field or
 * with a modal dialog open, and they can be switched off in the help dialog
 * (WCAG 2.1.4, character key shortcuts).
 */
export const SHORTCUTS = [
  { id: 'help', keys: ['?'], group: 'global', labelKey: 'shortcuts.help' },
  { id: 'search', keys: ['/'], group: 'global', labelKey: 'shortcuts.search' },
  { id: 'goDashboard', keys: ['g', 'd'], group: 'global', labelKey: 'shortcuts.goDashboard' },
  { id: 'goOrders', keys: ['g', 'o'], group: 'global', labelKey: 'shortcuts.goOrders' },
  { id: 'goProducts', keys: ['g', 'p'], group: 'global', labelKey: 'shortcuts.goProducts' },
  { id: 'goCustomers', keys: ['g', 'c'], group: 'global', labelKey: 'shortcuts.goCustomers' },
  { id: 'goLocations', keys: ['g', 'l'], group: 'global', labelKey: 'shortcuts.goLocations' },

  { id: 'table.move', keys: ['Arrows'], group: 'table', labelKey: 'shortcuts.table.move', builtIn: true },
  { id: 'table.open', keys: ['Enter'], group: 'table', labelKey: 'shortcuts.table.open', builtIn: true },
  { id: 'table.select', keys: [' '], group: 'table', labelKey: 'shortcuts.table.select', builtIn: true },
  { id: 'table.selectAll', keys: ['Control+a'], group: 'table', labelKey: 'shortcuts.table.selectAll', builtIn: true },
  { id: 'table.clear', keys: ['Escape'], group: 'table', labelKey: 'shortcuts.table.clear', builtIn: true },

  { id: 'orders.new', keys: ['n'], group: 'orderList', labelKey: 'shortcuts.orders.new' },
  { id: 'orders.tagSelected', keys: ['t'], group: 'orderList', labelKey: 'shortcuts.orders.tagSelected' },

  { id: 'order.advance', keys: ['a'], group: 'orderDetail', labelKey: 'shortcuts.order.advance' },
  { id: 'order.ship', keys: ['s'], group: 'orderDetail', labelKey: 'shortcuts.order.ship' },
  { id: 'order.edit', keys: ['e'], group: 'orderDetail', labelKey: 'shortcuts.order.edit' },
  { id: 'order.note', keys: ['n'], group: 'orderDetail', labelKey: 'shortcuts.order.note' },
  { id: 'order.tag', keys: ['t'], group: 'orderDetail', labelKey: 'shortcuts.order.tag' },
  { id: 'order.print', keys: ['p'], group: 'orderDetail', labelKey: 'shortcuts.order.print' },
  { id: 'order.back', keys: ['u'], group: 'orderDetail', labelKey: 'shortcuts.order.back' },
];

export const SHORTCUT_GROUPS = ['global', 'table', 'orderList', 'orderDetail'];

/** A sequence's next key has to follow within this long. */
export const SEQUENCE_TIMEOUT_MS = 1500;

/** Whether a key press is for the page's shortcuts at all. */
export function isShortcutEvent(event, doc = globalThis.document) {
  if (event.defaultPrevented || event.repeat || event.isComposing) return false;
  if (event.ctrlKey || event.metaKey || event.altKey) return false;
  if (event.target instanceof Element && isTyping(event.target)) return false;
  // A modal dialog owns the keyboard (the help dialog closes with Escape on its own).
  if (doc?.querySelector('dialog[open]')) return false;

  return event.key.length === 1;
}

/**
 * Follows key presses and says when they complete a shortcut. `feed` takes
 * the key and the shortcuts that have a handler right now, and returns the
 * one completed, or null (also while a sequence is under way).
 */
export function createMatcher({ timeout = SEQUENCE_TIMEOUT_MS, now = () => Date.now() } = {}) {
  let pending = [];
  let last = 0;

  const startsWith = (keys, prefix) => prefix.every((key, index) => keys[index] === key);

  function feed(key, active) {
    const time = now();
    if (time - last > timeout) pending = [];
    last = time;

    for (const attempt of [[...pending, key], [key]]) {
      const exact = active.find((shortcut) => shortcut.keys.length === attempt.length && startsWith(shortcut.keys, attempt));
      if (exact) {
        pending = [];
        return exact;
      }
      if (active.some((shortcut) => shortcut.keys.length > attempt.length && startsWith(shortcut.keys, attempt))) {
        pending = attempt;
        return null;
      }
      if (pending.length === 0) break;
    }
    pending = [];

    return null;
  }

  return { feed, reset: () => (pending = []), pending: () => [...pending] };
}

const ENABLED_KEY = 'kanso.shortcuts';

/** Single-key shortcuts are on unless this browser's user switched them off. */
export function loadShortcutsEnabled(storage = globalThis.localStorage) {
  try {
    return storage?.getItem(ENABLED_KEY) !== 'off';
  } catch {
    return true;
  }
}

export function saveShortcutsEnabled(enabled, storage = globalThis.localStorage) {
  try {
    if (enabled) storage?.removeItem(ENABLED_KEY);
    else storage?.setItem(ENABLED_KEY, 'off');
  } catch {
    // Private windows may refuse storage; the choice then lasts until reload.
  }
}
