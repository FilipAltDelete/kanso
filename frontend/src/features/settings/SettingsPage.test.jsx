import { fireEvent, render, screen, within } from '@testing-library/react';
import { I18nProvider } from '../../lib/i18n.jsx';
import { ShortcutsProvider } from '../../lib/ShortcutsProvider.jsx';
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

  describe('keyboard shortcuts', () => {
    beforeAll(() => {
      // jsdom has <dialog> but not its modal behaviour.
      HTMLDialogElement.prototype.showModal ??= function showModal() {
        this.open = true;
      };
      HTMLDialogElement.prototype.close ??= function close() {
        this.open = false;
        this.dispatchEvent(new Event('close'));
      };
    });

    function renderWithShortcuts() {
      render(
        <I18nProvider locale="en">
          <ShortcutsProvider>
            <SettingsPage />
          </ShortcutsProvider>
        </I18nProvider>,
      );

      return screen.getByRole('region', { name: 'Keyboard shortcuts' });
    }

    it('turns single-key shortcuts off and on, remembered in this browser', () => {
      const section = renderWithShortcuts();
      const toggle = within(section).getByRole('checkbox', { name: 'Single-key shortcuts' });
      expect(toggle.checked).toBe(true);

      fireEvent.click(toggle);

      expect(toggle.checked).toBe(false);
      expect(Object.values(window.localStorage)).toContain('off');

      fireEvent.click(toggle);
      expect(toggle.checked).toBe(true);
      expect(Object.values(window.localStorage)).not.toContain('off');
    });

    it('opens the list of shortcuts', () => {
      const section = renderWithShortcuts();

      fireEvent.click(within(section).getByRole('button', { name: 'Show keyboard shortcuts' }));

      expect(screen.getByRole('dialog', { name: 'Keyboard shortcuts' })).toBeTruthy();
    });
  });
});
