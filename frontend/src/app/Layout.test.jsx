import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { AuthContext } from '../features/auth/AuthProvider.jsx';
import { I18nProvider } from '../lib/i18n.jsx';
import { ShortcutsProvider } from '../lib/ShortcutsProvider.jsx';
import { Layout } from './Layout.jsx';
import { WorkspaceProvider } from './workspace/WorkspaceProvider.jsx';

// The pages ask the API for their data; the shell does not need it.
vi.mock('../api/client.js', () => ({ api: vi.fn(() => new Promise(() => {})), ApiError: class extends Error {} }));

const user = { id: 'u1', email: 'ops@example.com', name: 'Ops', roles: ['ROLE_OPERATOR'] };

function renderApp() {
  return render(
    <I18nProvider locale="sv">
      <QueryClientProvider client={new QueryClient()}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <WorkspaceProvider userId={user.id}>
            <ShortcutsProvider>
              <Layout />
            </ShortcutsProvider>
          </WorkspaceProvider>
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );
}

const tabs = () => within(screen.getByRole('tablist', { name: 'Öppna sidor' })).getAllByRole('tab');
const tabNames = () => tabs().map((tab) => tab.textContent);
const selected = () => tabs().find((tab) => tab.getAttribute('aria-selected') === 'true')?.textContent;
const menu = () => screen.getByRole('navigation', { name: 'Huvudmeny' });

describe('the signed-in shell', () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.history.replaceState(null, '', '/');
  });

  it('opens on the page in the address bar, as a tab, with the user in the menu', async () => {
    renderApp();

    expect(await screen.findByRole('heading', { name: 'Översikt' })).toBeTruthy();
    expect(tabNames()).toEqual(['Översikt']);
    expect(within(menu()).getByText('Ops')).toBeTruthy();
  });

  it('opens menu pages as tabs, and brings one forward instead of opening it twice', async () => {
    renderApp();
    await screen.findByRole('heading', { name: 'Översikt' });

    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }));
    expect(tabNames()).toEqual(['Översikt', 'Ordrar']);
    expect(selected()).toBe('Ordrar');
    expect(window.location.pathname).toBe('/orders');

    fireEvent.click(within(menu()).getByRole('link', { name: 'Översikt' }));
    expect(tabNames()).toEqual(['Översikt', 'Ordrar']);
    expect(selected()).toBe('Översikt');
    expect(window.location.pathname).toBe('/');
  });

  it('opens a second tab behind the one in front on ctrl-click', async () => {
    renderApp();
    await screen.findByRole('heading', { name: 'Översikt' });
    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }));
    fireEvent.click(within(menu()).getByRole('link', { name: 'Översikt' }));

    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }), { ctrlKey: true });

    expect(tabNames()).toEqual(['Översikt', 'Ordrar', 'Ordrar']);
    expect(selected()).toBe('Översikt');
  });

  it('closes tabs with their button and with Alt+W', async () => {
    renderApp();
    await screen.findByRole('heading', { name: 'Översikt' });
    fireEvent.click(within(menu()).getByRole('link', { name: 'Kunder' }));
    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }));

    fireEvent.click(screen.getByRole('button', { name: 'Stäng Kunder' }));
    expect(tabNames()).toEqual(['Översikt', 'Ordrar']);

    fireEvent.keyDown(document, { code: 'KeyW', key: 'w', altKey: true });
    expect(tabNames()).toEqual(['Översikt']);
    expect(selected()).toBe('Översikt');
  });

  it('opens the dashboard again when the last tab is closed', async () => {
    renderApp();
    await screen.findByRole('heading', { name: 'Översikt' });
    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stäng Översikt' }));
    expect(tabNames()).toEqual(['Ordrar']);

    fireEvent.click(screen.getByRole('button', { name: 'Stäng Ordrar' }));

    expect(tabNames()).toEqual(['Översikt']);
    expect(await screen.findByRole('heading', { name: 'Översikt' })).toBeTruthy();
    expect(window.location.pathname).toBe('/');
  });

  it('comes back with its tabs after a reload', async () => {
    const first = renderApp();
    await screen.findByRole('heading', { name: 'Översikt' });
    fireEvent.click(within(menu()).getByRole('link', { name: 'Ordrar' }));
    first.unmount();

    renderApp();

    await waitFor(() => expect(tabNames()).toEqual(['Översikt', 'Ordrar']));
    expect(selected()).toBe('Ordrar');
  });
});
