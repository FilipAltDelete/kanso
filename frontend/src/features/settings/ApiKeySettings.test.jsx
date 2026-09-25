import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '../auth/AuthProvider.jsx';
import { mockApi } from '../customers/testing.jsx';
import { I18nProvider } from '../../lib/i18n.jsx';
import { ApiKeySettings } from './ApiKeySettings.jsx';

const shopify = {
  id: '0192f000-0000-7000-8000-00000000000a',
  name: 'Shopify sync',
  role: 'ROLE_OPERATOR',
  status: 'active',
  createdBy: 'u1',
  createdByName: 'Admin',
  createdAt: '2026-09-20T08:00:00+00:00',
  expiresAt: null,
  lastUsedAt: '2026-09-25T07:00:00+00:00',
  revokedAt: null,
};
const old = { ...shopify, id: '0192f000-0000-7000-8000-00000000000b', name: 'Old ERP', status: 'revoked', createdBy: null, createdByName: null, lastUsedAt: null, revokedAt: '2026-09-21T08:00:00+00:00' };

function renderSettings(roles, routes = {}) {
  const calls = mockApi({ 'GET /api/api-keys': { member: [shopify, old], totalItems: 2 }, ...routes });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const user = { id: 'u1', email: 'admin@example.com', name: 'Admin', roles };

  render(
    <I18nProvider locale="en">
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <ApiKeySettings />
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );

  return calls;
}

describe('API key settings', () => {
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

    expect(screen.queryByRole('region', { name: 'API keys' })).toBeNull();
    expect(calls).toHaveLength(0);
  });

  it('lists the keys with their status, and offers revoking only the live ones', async () => {
    renderSettings(['ROLE_ADMIN']);

    const section = screen.getByRole('region', { name: 'API keys' });
    expect(await within(section).findByText('Shopify sync')).toBeTruthy();
    expect(within(section).getByText('Active')).toBeTruthy();
    expect(within(section).getByText('Revoked')).toBeTruthy();
    expect(within(section).getByText('from the console')).toBeTruthy();
    expect(within(section).getByRole('button', { name: 'Revoke Shopify sync' })).toBeTruthy();
    expect(within(section).queryByRole('button', { name: 'Revoke Old ERP' })).toBeNull();
  });

  it('creates a key and shows it once', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], {
      'POST /api/api-keys': { status: 201, body: { ...shopify, id: 'new', name: 'ERP', role: 'ROLE_VIEWER', lastUsedAt: null, key: 'kso_secret' } },
    });

    fireEvent.click(screen.getByRole('button', { name: 'New API key' }));
    const dialog = screen.getByRole('dialog', { name: 'New API key' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create key' }));
    expect(within(dialog).getByText('Give the key a name.')).toBeTruthy();

    fireEvent.change(within(dialog).getByRole('textbox', { name: /Name/ }), { target: { value: ' ERP ' } });
    fireEvent.change(within(dialog).getByRole('combobox', { name: /Role/ }), { target: { value: 'ROLE_VIEWER' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create key' }));

    const created = await screen.findByRole('dialog', { name: 'API key created' });
    expect(within(created).getByRole('textbox', { name: 'Key' }).value).toBe('kso_secret');
    expect(calls.find((call) => call.method === 'POST').body).toEqual({ name: 'ERP', role: 'ROLE_VIEWER', expiresAt: null });

    fireEvent.click(within(created).getByRole('button', { name: 'Done' }));
    expect(screen.queryByText('kso_secret')).toBeNull();
    expect(screen.queryByDisplayValue('kso_secret')).toBeNull();
  });

  it('sends an expiry date as the start of that day', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], { 'POST /api/api-keys': { status: 201, body: { ...shopify, key: 'kso_secret' } } });

    fireEvent.click(screen.getByRole('button', { name: 'New API key' }));
    const dialog = screen.getByRole('dialog', { name: 'New API key' });
    fireEvent.change(within(dialog).getByRole('textbox', { name: /Name/ }), { target: { value: 'ERP' } });
    fireEvent.change(within(dialog).getByLabelText('Expires on'), { target: { value: '2099-03-01' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create key' }));

    await screen.findByRole('dialog', { name: 'API key created' });
    expect(calls.find((call) => call.method === 'POST').body.expiresAt).toBe(new Date(2099, 2, 1).toISOString());
  });

  it('shows the server\'s objections at their fields', async () => {
    renderSettings(['ROLE_ADMIN'], {
      'POST /api/api-keys': { status: 422, body: { title: 'Invalid', violations: [{ path: 'expiresAt', code: 'in_past', message: 'The expiry must be in the future.' }] } },
    });

    fireEvent.click(screen.getByRole('button', { name: 'New API key' }));
    const dialog = screen.getByRole('dialog', { name: 'New API key' });
    fireEvent.change(within(dialog).getByRole('textbox', { name: /Name/ }), { target: { value: 'ERP' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create key' }));

    expect(await within(dialog).findByText('The expiry must be in the future.')).toBeTruthy();
    expect(within(dialog).getByLabelText('Expires on').getAttribute('aria-invalid')).toBe('true');
  });

  it('revokes a key after asking', async () => {
    const calls = renderSettings(['ROLE_ADMIN'], { [`POST /api/api-keys/${shopify.id}/revoke`]: { ...shopify, status: 'revoked', revokedAt: '2026-09-25T12:00:00+00:00' } });

    fireEvent.click(await screen.findByRole('button', { name: 'Revoke Shopify sync' }));
    const dialog = screen.getByRole('dialog', { name: 'Revoke "Shopify sync"?' });
    expect(calls.some((call) => call.method === 'POST')).toBe(false);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(calls.filter((call) => call.method === 'POST').map((call) => call.url)).toEqual([`/api/api-keys/${shopify.id}/revoke`]);
  });
});
