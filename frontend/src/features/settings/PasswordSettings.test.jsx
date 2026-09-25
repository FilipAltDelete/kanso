import { fireEvent, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '../auth/AuthProvider.jsx';
import { mockApi } from '../customers/testing.jsx';
import { I18nProvider } from '../../lib/i18n.jsx';
import { PasswordSettings } from './PasswordSettings.jsx';

function renderSettings(routes = {}) {
  const calls = mockApi(routes);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const user = { id: 'u1', email: 'viewer@example.com', name: null, roles: ['ROLE_VIEWER'] };

  render(
    <I18nProvider locale="en">
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <PasswordSettings />
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );

  return calls;
}

function fill(section, { current, next, repeat = next }) {
  fireEvent.change(within(section).getByLabelText(/Current password/), { target: { value: current } });
  fireEvent.change(within(section).getByLabelText(/^New password \(/), { target: { value: next } });
  fireEvent.change(within(section).getByLabelText(/New password again/), { target: { value: repeat } });
  fireEvent.click(within(section).getByRole('button', { name: 'Change password' }));
}

describe('Password settings', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('checks the new password before sending it', () => {
    const calls = renderSettings();
    const section = screen.getByRole('region', { name: 'Your password' });

    fill(section, { current: 'old', next: 'short', repeat: 'shorter' });

    expect(within(section).getByText('At least 8 characters.', { selector: '[id$="-error"]' })).toBeTruthy();
    expect(within(section).getByText('The passwords do not match.')).toBeTruthy();
    expect(calls).toHaveLength(0);
  });

  it('changes the password, and the session goes on', async () => {
    const calls = renderSettings({ 'POST /api/auth/password': { accessToken: 'new-token', expiresIn: 900, tokenType: 'Bearer' } });
    const section = screen.getByRole('region', { name: 'Your password' });

    fill(section, { current: 'old password', next: 'a new password' });

    expect(await within(section).findByText('Your password is changed.')).toBeTruthy();
    expect(calls[0].body).toEqual({ currentPassword: 'old password', newPassword: 'a new password' });
    expect(within(section).getByLabelText(/Current password/).value).toBe('');
  });

  it('says so when the current password is wrong', async () => {
    renderSettings({
      'POST /api/auth/password': { status: 422, body: { title: 'Invalid', violations: [{ path: 'currentPassword', code: 'wrong_password', message: 'x' }] } },
    });
    const section = screen.getByRole('region', { name: 'Your password' });

    fill(section, { current: 'guess', next: 'a new password' });

    expect(await within(section).findByText('This is not your current password.')).toBeTruthy();
    expect(within(section).getByLabelText(/Current password/).getAttribute('aria-invalid')).toBe('true');
  });
});
