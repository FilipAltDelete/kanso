import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '../auth/AuthProvider.jsx';
import { mockApi } from '../customers/testing.jsx';
import { I18nProvider } from '../../lib/i18n.jsx';
import { UserSettings } from './UserSettings.jsx';

const me = { id: 'u1', email: 'admin@example.com', name: 'Admin', role: 'ROLE_ADMIN', status: 'active', createdAt: '2026-09-01T08:00:00+00:00' };
const pia = { id: 'u2', email: 'pia@example.com', name: 'Pia Picker', role: 'ROLE_OPERATOR', status: 'active', createdAt: '2026-09-20T08:00:00+00:00' };
const gone = { id: 'u3', email: 'gone@example.com', name: null, role: 'ROLE_VIEWER', status: 'deactivated', createdAt: '2026-09-21T08:00:00+00:00' };

function renderSettings(roles, routes = {}) {
  const calls = mockApi({ 'GET /api/users': { member: [me, pia, gone], totalItems: 3 }, ...routes });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const user = { id: 'u1', email: 'admin@example.com', name: 'Admin', roles };

  render(
    <I18nProvider locale="en">
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <UserSettings />
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );

  return calls;
}

describe('User settings', () => {
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

  afterEach(() => vi.unstubAllGlobals());

  it('is not there for anyone but an admin', () => {
    const calls = renderSettings(['ROLE_OPERATOR']);

    expect(screen.queryByRole('region', { name: 'Users' })).toBeNull();
    expect(calls).toHaveLength(0);
  });

  it('lists the users, and never offers deactivating yourself', async () => {
    renderSettings(['ROLE_ADMIN']);

    const section = screen.getByRole('region', { name: 'Users' });
    expect(await within(section).findByText('Pia Picker')).toBeTruthy();
    expect(within(section).getByText('(you)')).toBeTruthy();
    expect(within(section).getByText('Deactivated', { selector: ':not(option)' })).toBeTruthy();
    expect(within(section).getByRole('button', { name: 'Deactivate Pia Picker' })).toBeTruthy();
    expect(within(section).getByRole('button', { name: 'Activate gone@example.com' })).toBeTruthy();
    expect(within(section).queryByRole('button', { name: 'Deactivate Admin' })).toBeNull();
    expect(within(section).queryByRole('button', { name: 'Set a password for Admin' })).toBeNull();
  });

  it('filters by status', async () => {
    const calls = renderSettings(['ROLE_ADMIN']);
    await screen.findByText('Pia Picker');

    fireEvent.change(screen.getByRole('combobox', { name: 'Show' }), { target: { value: 'deactivated' } });

    await waitFor(() => expect(calls.at(-1).url).toContain('status=deactivated'));
  });

  it('adds someone with a first password', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], { 'POST /api/users': { status: 201, body: { ...pia, id: 'u4', email: 'new@example.com', name: null, role: 'ROLE_VIEWER' } } });

    fireEvent.click(screen.getByRole('button', { name: 'New user' }));
    const dialog = screen.getByRole('dialog', { name: 'New user' });
    fireEvent.change(within(dialog).getByLabelText(/Email/), { target: { value: 'new@example.com' } });
    fireEvent.change(within(dialog).getByLabelText(/First password/), { target: { value: 'short' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add user' }));
    expect(within(dialog).getByText('At least 8 characters.', { selector: '[id$="-error"]' })).toBeTruthy();
    expect(calls.some((call) => call.method === 'POST')).toBe(false);

    fireEvent.change(within(dialog).getByLabelText(/First password/), { target: { value: 'long enough' } });
    fireEvent.change(within(dialog).getByRole('combobox', { name: /Role/ }), { target: { value: 'ROLE_VIEWER' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add user' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(calls.find((call) => call.method === 'POST').body).toEqual({ email: 'new@example.com', name: null, role: 'ROLE_VIEWER', password: 'long enough' });
  });

  it('shows the last-admin refusal at the role', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], {
      'PATCH /api/users/u1': { status: 409, body: { title: 'Conflict', detail: 'x', violations: [{ path: 'role', code: 'last_admin', message: 'x' }] } },
    });

    fireEvent.click(await screen.findByRole('button', { name: 'Edit Admin' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit Admin' });
    expect(within(dialog).queryByLabelText(/password/i)).toBeNull();
    fireEvent.change(within(dialog).getByRole('combobox', { name: /Role/ }), { target: { value: 'ROLE_OPERATOR' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

    expect(await within(dialog).findByText('The last active admin must stay an admin. Make someone else an admin first.')).toBeTruthy();
    expect(within(dialog).getByRole('combobox', { name: /Role/ }).getAttribute('aria-invalid')).toBe('true');
    expect(calls.find((call) => call.method === 'PATCH').body).toEqual({ email: 'admin@example.com', name: 'Admin', role: 'ROLE_OPERATOR' });
  });

  it('deactivates someone after asking', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], { 'POST /api/users/u2/deactivate': { ...pia, status: 'deactivated' } });

    fireEvent.click(await screen.findByRole('button', { name: 'Deactivate Pia Picker' }));
    const dialog = screen.getByRole('dialog', { name: 'Deactivate Pia Picker?' });
    expect(calls.some((call) => call.method === 'POST')).toBe(false);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Deactivate' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(calls.filter((call) => call.method === 'POST').map((call) => call.url)).toEqual(['/api/users/u2/deactivate']);
  });

  it('explains a refused deactivation in words', async () => {
    renderSettings(['ROLE_ADMIN'], {
      'POST /api/users/u2/deactivate': { status: 409, body: { title: 'Conflict', detail: 'x', violations: [{ path: '', code: 'last_admin', message: 'x' }] } },
    });

    fireEvent.click(await screen.findByRole('button', { name: 'Deactivate Pia Picker' }));
    const dialog = screen.getByRole('dialog', { name: 'Deactivate Pia Picker?' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Deactivate' }));

    expect(await within(dialog).findByText('The last active admin cannot be deactivated. Make someone else an admin first.')).toBeTruthy();
  });

  it('sets a forgotten password', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], { 'POST /api/users/u2/password': pia });

    fireEvent.click(await screen.findByRole('button', { name: 'Set a password for Pia Picker' }));
    const dialog = screen.getByRole('dialog', { name: 'Set a password for Pia Picker' });
    fireEvent.change(within(dialog).getByLabelText(/New password/), { target: { value: 'a new password' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Set password' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(calls.find((call) => call.method === 'POST').body).toEqual({ password: 'a new password' });
  });
});
