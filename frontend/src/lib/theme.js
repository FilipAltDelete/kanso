/**
 * Colour themes, as in Pimsen. A theme is nothing but a set of CSS variables
 * (styles.css, `:root[data-theme=…]`): Tailwind 4 reads every colour through a
 * variable, so redefining `slate-*`, `white` and the status colours re-skins
 * every screen with no component knowing a theme exists. A dark theme inverts
 * the slate scale — `slate-50` is the page, `slate-900` the strongest text — so
 * a class that means "subtle background" or "strong text" still means it.
 *
 * The choice is remembered in this browser and applied before the first
 * render (main.jsx), so a dark theme never flashes white on load.
 */

export const THEMES = [
  {
    id: 'kanso',
    label: 'Kanso',
    dark: false,
    preview: { background: '#ffffff', foreground: '#0f172a', border: '#e2e8f0' },
    dots: ['#0f172a', '#64748b', '#f25c54', '#2fb170'],
  },
  {
    id: 'tokyo-night',
    label: 'Tokyo Night',
    dark: true,
    preview: { background: '#1a1b26', foreground: '#c0caf5', border: '#3b4261' },
    dots: ['#bb9af7', '#7aa2f7', '#9ece6a', '#ff9e64'],
  },
  {
    id: 'osaka-jade',
    label: 'Osaka Jade',
    dark: true,
    preview: { background: '#111c18', foreground: '#c1c497', border: '#2e463c' },
    dots: ['#d2689c', '#549e6a', '#63b07a', '#8cd3a0'],
  },
  {
    id: 'wine-red',
    label: 'Wine Red',
    dark: true,
    preview: { background: '#1b1015', foreground: '#ecd9de', border: '#4a2c38' },
    dots: ['#f07a94', '#c3a0e8', '#b4c77a', '#e8a36a'],
  },
  {
    id: 'lupine',
    label: 'Lupine',
    dark: false,
    preview: { background: '#ffffff', foreground: '#1f1840', border: '#e1d9f3' },
    dots: ['#9b51e0', '#2f6fed', '#5b3fd6', '#0a84d6'],
  },
  {
    id: 'moonrise',
    label: 'Moonrise',
    dark: true,
    preview: { background: '#120c26', foreground: '#e6ddf7', border: '#35275f' },
    dots: ['#e8488a', '#a48cf5', '#ff9f5a', '#ffe3b8'],
  },
];

export const DEFAULT_THEME = 'kanso';
const STORAGE_KEY = 'kanso.theme';

export function themeById(id) {
  return THEMES.find((theme) => theme.id === id) ?? THEMES.find((theme) => theme.id === DEFAULT_THEME);
}

/** The saved choice. Storage can be missing or refuse (private windows); that is the default theme, not an error. */
export function savedTheme() {
  try {
    return themeById(window.localStorage.getItem(STORAGE_KEY)).id;
  } catch {
    return DEFAULT_THEME;
  }
}

export function applyTheme(id) {
  const theme = themeById(id);
  const root = document.documentElement;
  root.dataset.theme = theme.id;
  // Native controls — selects, checkboxes, date pickers, scrollbars — follow this.
  root.style.colorScheme = theme.dark ? 'dark' : 'light';
}

export function saveTheme(id) {
  applyTheme(id);
  try {
    window.localStorage.setItem(STORAGE_KEY, themeById(id).id);
  } catch {
    // Not remembered in this browser; applied for this visit all the same.
  }
}
