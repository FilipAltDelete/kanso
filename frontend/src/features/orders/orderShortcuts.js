import { useNavigate } from '@tanstack/react-router';
import { useShortcuts } from '../../lib/ShortcutsProvider.jsx';
import { useCanOperate } from './shared.jsx';

/**
 * The order pages' keyboard shortcuts (keys and labels are in lib/shortcuts.js).
 * They act through the page's own controls, found by `data-shortcut` (or
 * `data-bulk-action`), so a shortcut does exactly what the click does, is
 * refused when the control is disabled, and does nothing when it is not shown.
 */

/** Clicks the control `selector` finds; false (the key goes to the browser) when there is none to click. */
function click(selector) {
  return () => {
    const control = document.querySelector(selector);
    if (!control || control.disabled) return false;
    control.click();
  };
}

function focus(selector) {
  return () => {
    const field = document.querySelector(selector);
    if (!field || field.disabled) return false;
    field.focus();
  };
}

export function useOrderListShortcuts({ canOperate, onNothingSelected }) {
  const navigate = useNavigate();

  useShortcuts({
    'orders.new': canOperate ? () => navigate({ to: '/orders/new' }) : undefined,
    'orders.tagSelected': canOperate
      ? () => {
          // The bulk-action bar, and its "Add tag", is there only while rows are selected.
          const add = document.querySelector('[data-bulk-action="add-tag"]');
          if (add) add.click();
          else onNothingSelected();
        }
      : undefined,
  });
}

export function useOrderDetailShortcuts() {
  const navigate = useNavigate();
  const canOperate = useCanOperate();
  const operate = (handler) => (canOperate ? handler : undefined);

  useShortcuts({
    'order.advance': operate(click('[data-shortcut="advance"]')),
    'order.ship': operate(click('[data-shortcut="ship"]')),
    'order.edit': operate(click('[data-shortcut="edit"]')),
    'order.note': operate(focus('[data-shortcut="note"]')),
    'order.tag': operate(focus('[data-shortcut="tag"]')),
    'order.print': click('[data-shortcut="print"]'),
    'order.back': () => navigate({ to: '/orders' }),
  });
}
