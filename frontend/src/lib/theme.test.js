import { applyTheme, DEFAULT_THEME, saveTheme, savedTheme, themeById, THEMES } from './theme.js';

describe('themes', () => {
  afterEach(() => window.localStorage.clear());

  it('has unique ids and falls back to the default for an unknown one', () => {
    expect(new Set(THEMES.map((theme) => theme.id)).size).toBe(THEMES.length);
    expect(themeById('no-such-theme').id).toBe(DEFAULT_THEME);
  });

  it('applies a theme to the document with the matching colour scheme', () => {
    applyTheme('tokyo-night');
    expect(document.documentElement.dataset.theme).toBe('tokyo-night');
    expect(document.documentElement.style.colorScheme).toBe('dark');

    applyTheme('lupine');
    expect(document.documentElement.style.colorScheme).toBe('light');
  });

  it('remembers the saved theme', () => {
    expect(savedTheme()).toBe(DEFAULT_THEME);
    saveTheme('moonrise');
    expect(savedTheme()).toBe('moonrise');
  });
});
