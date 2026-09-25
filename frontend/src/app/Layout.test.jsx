import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { RouterProvider } from '@tanstack/react-router';
import { AuthContext } from '../features/auth/AuthProvider.jsx';
import { I18nProvider } from '../lib/i18n.jsx';
import { router } from './router.jsx';

// The dashboard asks the API for its numbers; the shell does not need them.
vi.mock('../api/client.js', () => ({ api: vi.fn(() => new Promise(() => {})), ApiError: class extends Error {} }));

describe('the signed-in shell', () => {
  it('opens on the dashboard with the user in the header', async () => {
    const user = { id: '1', email: 'ops@example.com', name: 'Ops', roles: ['ROLE_OPERATOR'] };

    render(
      <I18nProvider locale="sv">
        <QueryClientProvider client={new QueryClient()}>
          <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
            <RouterProvider router={router} />
          </AuthContext.Provider>
        </QueryClientProvider>
      </I18nProvider>,
    );

    expect(await screen.findByRole('heading', { name: 'Översikt' })).toBeTruthy();
    expect(screen.getByText('Ops')).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Huvudmeny' })).toBeTruthy();
  });
});
