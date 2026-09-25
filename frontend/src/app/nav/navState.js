/**
 * The menu's own state, as in Pimsen: whether it is collapsed to its icons and
 * which folders are open. Remembered in this browser only; unreadable storage
 * (a private window) just means expanded with every folder open.
 */
const COLLAPSED_KEY = 'kanso.nav.collapsed';
const FOLDERS_KEY = 'kanso.nav.folders';

export function loadCollapsed() {
  try {
    return window.localStorage.getItem(COLLAPSED_KEY) === '1';
  } catch {
    return false;
  }
}

export function saveCollapsed(collapsed) {
  try {
    window.localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
  } catch {
    // Not remembered; the toggle still works for this visit.
  }
}

/** `{ [folderId]: open }`; a folder never toggled is absent and takes its default. */
export function loadFolders() {
  try {
    const stored = JSON.parse(window.localStorage.getItem(FOLDERS_KEY) ?? '{}');
    return stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};
  } catch {
    return {};
  }
}

export function saveFolders(folders) {
  try {
    window.localStorage.setItem(FOLDERS_KEY, JSON.stringify(folders));
  } catch {
    // Not remembered; the folders still fold for this visit.
  }
}

/**
 * The entry for the page in front: the longest href that is the path or a
 * parent of it, so /products/import marks Import and not Products, and an
 * order's page marks All orders.
 */
export function activeHref(pathname, hrefs) {
  let best = null;
  for (const href of hrefs) {
    const matches = href === '/' ? pathname === '/' : pathname === href || pathname.startsWith(`${href}/`);
    if (matches && (best === null || href.length > best.length)) best = href;
  }
  return best;
}
