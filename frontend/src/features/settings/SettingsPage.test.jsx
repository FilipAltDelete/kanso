import { fireEvent, render, screen } from '@testing-library/react';
import { I18nProvider } from '../../lib/i18n.jsx';
import { SettingsPage } from './SettingsPage.jsx';

describe('the settings page', () => {
  afterEach(() => window.localStorage.clear());

  it('applies and remembers the picked theme', () => {
    render(
      <I18nProvider locale="en">
        <SettingsPage />
      </I18nProvider>,
    );

    expect(screen.getByRole('radio', { name: 'Kanso' }).getAttribute('aria-checked')).toBe('true');

    fireEvent.click(screen.getByRole('radio', { name: 'Tokyo Night' }));

    expect(screen.getByRole('radio', { name: 'Tokyo Night' }).getAttribute('aria-checked')).toBe('true');
    expect(document.documentElement.dataset.theme).toBe('tokyo-night');
    expect(window.localStorage.getItem('kanso.theme')).toBe('tokyo-night');
  });

  it('moves between themes with the arrow keys', () => {
    render(
      <I18nProvider locale="en">
        <SettingsPage />
      </I18nProvider>,
    );

    fireEvent.keyDown(screen.getByRole('radio', { name: 'Kanso' }), { key: 'ArrowLeft' });

    expect(screen.getByRole('radio', { name: 'Moonrise' }).getAttribute('aria-checked')).toBe('true');
  });
});
