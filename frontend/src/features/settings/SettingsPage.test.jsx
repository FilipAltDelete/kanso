import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryHistory, createRootRoute, createRoute, createRouter, Outlet, RouterProvider } from '@tanstack/react-router';
import { api } from '../../api/client.js';
import { I18nProvider } from '../../lib/i18n.jsx';
import { ShortcutsProvider } from '../../lib/ShortcutsProvider.jsx';
import { AuthContext } from '../auth/AuthProvider.jsx';
import { SettingsPage } from './SettingsPage.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const operator = { id: 'u1', email: 'olle@example.com', name: 'Olle', roles: ['ROLE_OPERATOR'] };
const admin = { id: 'u2', email: 'admin@example.com', name: 'Admin', roles: ['ROLE_ADMIN'] };

/** The settings page at `url` under a memory router, signed in as `user`. */
async function renderSettings({ url = '/settings', user = operator } = {}) {
  const root = createRootRoute({ component: Outlet });
  const router = createRouter({
    routeTree: root.addChildren([createRoute({ getParentRoute: () => root, path: '/settings', component: SettingsPage })]),
    history: createMemoryHistory({ initialEntries: [url] }),
  });

  render(
    <I18nProvider locale="en">
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <ShortcutsProvider>
            <RouterProvider router={router} />
          </ShortcutsProvider>
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );
  await screen.findByRole('heading', { name: 'Settings', level: 1 });

  return router;
}

describe('the settings page', () => {
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
  beforeEach(() => {
    api.mockReset();
    api.mockResolvedValue({ member: [], totalItems: 0 });
  });
  afterEach(() => window.localStorage.clear());

  describe('tabs', () => {
    it('offers everyone their preferences and account, and admins users and API keys too', async () => {
      await renderSettings();
      expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Preferences', 'Account']);
      expect(screen.getByRole('tab', { name: 'Preferences' }).getAttribute('aria-selected')).toBe('true');
      expect(screen.getByRole('tabpanel', { name: 'Preferences' })).toBeTruthy();
    });

    it('shows admins every tab', async () => {
      await renderSettings({ user: admin });

      expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Preferences', 'Account', 'Users', 'API keys']);
    });

    it('opens a tab, and keeps it in the URL', async () => {
      const router = await renderSettings({ user: admin });

      fireEvent.click(screen.getByRole('tab', { name: 'API keys' }));

      expect(await screen.findByRole('region', { name: 'API keys' })).toBeTruthy();
      expect(screen.queryByRole('radiogroup', { name: 'Theme' })).toBeNull();
      expect(router.state.location.search).toEqual({ tab: 'api-keys' });

      fireEvent.click(screen.getByRole('tab', { name: 'Preferences' }));
      expect(await screen.findByRole('radiogroup', { name: 'Theme' })).toBeTruthy();
      expect(router.state.location.search).toEqual({});
    });

    it('opens the tab the URL names', async () => {
      await renderSettings({ url: '/settings?tab=account' });

      expect(screen.getByRole('tabpanel', { name: 'Account' })).toBeTruthy();
      expect(screen.getByRole('region', { name: 'Your password' })).toBeTruthy();
    });

    it('opens the first tab instead of one the user may not see', async () => {
      await renderSettings({ url: '/settings?tab=users' });

      expect(screen.getByRole('tab', { name: 'Preferences' }).getAttribute('aria-selected')).toBe('true');
      expect(screen.queryByRole('region', { name: 'Users' })).toBeNull();
      expect(api).not.toHaveBeenCalled();
    });

    it('moves between tabs with the arrow keys, Home and End', async () => {
      await renderSettings({ user: admin });
      const tab = (name) => screen.getByRole('tab', { name });

      // Opening a tab goes through the router, which updates a moment later.
      const selected = (name) => waitFor(() => expect(tab(name).getAttribute('aria-selected')).toBe('true'));

      fireEvent.keyDown(tab('Preferences'), { key: 'ArrowRight' });
      await selected('Account');
      expect(document.activeElement).toBe(tab('Account'));

      fireEvent.keyDown(tab('Account'), { key: 'End' });
      await selected('API keys');

      fireEvent.keyDown(tab('API keys'), { key: 'ArrowRight' });
      await selected('Preferences');
      expect(tab('Preferences').tabIndex).toBe(0);
      expect(tab('Account').tabIndex).toBe(-1);
    });
  });

  it('applies and remembers the picked theme', async () => {
    await renderSettings();

    expect(screen.getByRole('radio', { name: 'Kanso' }).getAttribute('aria-checked')).toBe('true');

    fireEvent.click(screen.getByRole('radio', { name: 'Tokyo Night' }));

    expect(screen.getByRole('radio', { name: 'Tokyo Night' }).getAttribute('aria-checked')).toBe('true');
    expect(document.documentElement.dataset.theme).toBe('tokyo-night');
    expect(window.localStorage.getItem('kanso.theme')).toBe('tokyo-night');
  });

  it('moves between themes with the arrow keys', async () => {
    await renderSettings();

    fireEvent.keyDown(screen.getByRole('radio', { name: 'Kanso' }), { key: 'ArrowLeft' });

    expect(screen.getByRole('radio', { name: 'Moonrise' }).getAttribute('aria-checked')).toBe('true');
  });

  describe('keyboard shortcuts', () => {
    it('turns single-key shortcuts off and on, remembered in this browser', async () => {
      await renderSettings();
      const toggle = within(screen.getByRole('region', { name: 'Keyboard shortcuts' })).getByRole('checkbox', { name: 'Single-key shortcuts' });
      expect(toggle.checked).toBe(true);

      fireEvent.click(toggle);

      expect(toggle.checked).toBe(false);
      expect(Object.values(window.localStorage)).toContain('off');

      fireEvent.click(toggle);
      expect(toggle.checked).toBe(true);
      expect(Object.values(window.localStorage)).not.toContain('off');
    });

    it('opens the list of shortcuts', async () => {
      await renderSettings();

      fireEvent.click(within(screen.getByRole('region', { name: 'Keyboard shortcuts' })).getByRole('button', { name: 'Show keyboard shortcuts' }));

      expect(screen.getByRole('dialog', { name: 'Keyboard shortcuts' })).toBeTruthy();
    });
  });
});
