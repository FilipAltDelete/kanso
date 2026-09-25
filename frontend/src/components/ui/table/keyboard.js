/**
 * Where a key moves the active cell of a grid, following the WAI-ARIA grid
 * pattern, or `null` when the key is not a move. Row -1 is the header row.
 */
export function moveCell({ row, col }, event, { rows, cols, pageStep = 10 }) {
  const lastRow = rows - 1;
  const lastCol = cols - 1;
  const ctrl = event.ctrlKey || event.metaKey;

  switch (event.key) {
    case 'ArrowUp':
      return { row: Math.max(-1, row - 1), col };
    case 'ArrowDown':
      return { row: Math.min(lastRow, row + 1), col };
    case 'ArrowLeft':
      return { row, col: Math.max(0, col - 1) };
    case 'ArrowRight':
      return { row, col: Math.min(lastCol, col + 1) };
    case 'Home':
      return ctrl ? { row: -1, col: 0 } : { row, col: 0 };
    case 'End':
      return ctrl ? { row: Math.max(-1, lastRow), col: lastCol } : { row, col: lastCol };
    case 'PageUp':
      return { row: row < 0 ? -1 : Math.max(0, row - pageStep), col };
    case 'PageDown':
      return { row: Math.min(lastRow, row + pageStep), col };
    default:
      return null;
  }
}

/** What a cell hands focus to: its only widget if it has one (a checkbox, a sort button, a link), else itself. */
export const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])';

export function cellTarget(grid, { row, col }) {
  const cell = grid.querySelector(`[data-cell="${row}:${col}"]`);
  if (!cell) return null;

  return cell.querySelector(FOCUSABLE) ?? cell;
}

/** Keys typed into a text field are the field's, not the grid's. */
export function isTyping(element) {
  return element.matches('input:not([type=checkbox]):not([type=radio]), select, textarea, [contenteditable="true"]');
}
