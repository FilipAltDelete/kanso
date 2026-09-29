import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { channelsFixture, orderFixture } from '../features/orders/testing.jsx';
import { App, createQueryClient } from './App.jsx';

/**
 * The whole app over a fake server: the real API client, auth provider and
 * workspace, with only `fetch` replaced. The server takes one access token
 * at a time and can be told to refuse the refresh token.
 */
const user = { id: 'u1', email: 'ops@example.com', name: 'Ops', roles: ['ROLE_OPERATOR'] };

const dashboard = {
  date: '2026-09-26',
  timeZone: 'Europe/Stockholm',
  dayStart: '2026-09-25T22:00:00+00:00',
  dayEnd: '2026-09-26T22:00:00+00:00',
  ordersToday: 1,
  awaitingFulfillment: 0,
  shippedToday: 0,
  ordersByStatus: { pending: 1, confirmed: 0, allocated: 0, picking: 0, packed: 0, shipped: 0, delivered: 0, cancelled: 0, on_hold: 0 },
  stockOuts: { count: 0, items: [] },
  generatedAt: '2026-09-26T08:30:00+00:00',
};

function answer(status, body = null) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const tokens = (accessToken) => answer(200, { accessToken, expiresIn: 300, tokenType: 'Bearer' });

function fakeServer() {
  const state = { valid: 't1', refreshTokenGood: true };

  fetch.mockImplementation((url, init = {}) => {
    const path = url.split('?')[0];
    const method = init.method ?? 'GET';

    if (path === '/api/auth/refresh') return state.refreshTokenGood ? tokens(state.valid) : answer(401, { title: 'Unauthorized', detail: 'The session has expired. Sign in again.' });
    // Public, but an access token that has expired is refused, as the real API does.
    if (path === '/api/auth/logout') return init.headers?.Authorization && init.headers.Authorization !== `Bearer ${state.valid}` ? answer(401) : answer(204);
    if (path === '/api/auth/login') {
      state.valid = 't2';
      state.refreshTokenGood = true;
      return tokens('t2');
    }
    if (init.headers?.Authorization !== `Bearer ${state.valid}`) return answer(401, { title: 'Unauthorized' });

    if (path === '/api/auth/me') return answer(200, user);
    if (path === '/api/dashboard') return answer(200, dashboard);
    if (path === '/api/channels') return answer(200, channelsFixture);
    if (path === '/api/order-tags') return answer(200, { member: [] });
    if (path === '/api/orders' && method === 'GET') return answer(200, { member: [orderFixture()], totalItems: 1 });
    if (path === '/api/orders/o1' && method === 'GET') return answer(200, orderFixture());
    if (method === 'GET') return answer(200, { member: [], totalItems: 0 });
    return answer(200, orderFixture({ status: 'confirmed', version: 2 }));
  });

  return state;
}

const tabs = () => within(screen.getByRole('tablist', { name: 'Open pages' })).getAllByRole('tab');
const tabNames = () => tabs().map((tab) => tab.textContent);
const selected = () => tabs().find((tab) => tab.getAttribute('aria-selected') === 'true')?.textContent;
const menu = () => screen.getByRole('navigation', { name: 'Main navigation' });
const refreshes = () => fetch.mock.calls.filter(([url]) => url === '/api/auth/refresh').length;

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
  window.localStorage.clear();
  window.localStorage.setItem('kanso.locale', 'en');
  window.history.replaceState(null, '', '/');
});

afterEach(() => vi.unstubAllGlobals());

describe('a session that runs out', () => {
  it('returns to the login page, forgets what was loaded, and after signing in again opens every tab where it was', async () => {
    const server = fakeServer();
    const queryClient = createQueryClient();
    render(<App queryClient={queryClient} />);

    // Three tabs: the dashboard, an order opened from the list, and customers behind them.
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeTruthy();
    fireEvent.click(within(menu()).getByRole('link', { name: 'Orders' }));
    fireEvent.click(within(menu()).getByRole('link', { name: 'Customers' }));
    fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
    fireEvent.click(await screen.findByRole('link', { name: '10001' }));
    expect(await screen.findByRole('heading', { name: 'Order 10001' })).toBeTruthy();
    await waitFor(() => expect(tabNames()).toEqual(['Dashboard', 'Order 10001', 'Customers']));
    expect(selected()).toBe('Order 10001');
    expect(window.location.pathname).toBe('/orders/o1');
    const refreshesBefore = refreshes();

    // Back from lunch: the access token has expired and the refresh token has gone too.
    server.valid = 'expired';
    server.refreshTokenGood = false;
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

    expect(await screen.findByText('Your session has expired. Sign in again to carry on where you were.')).toBeTruthy();
    expect(screen.queryByRole('tablist')).toBeNull();
    expect(screen.getByLabelText('Email')).toHaveProperty('value', 'ops@example.com');
    expect(refreshes() - refreshesBefore).toBe(1);
    await waitFor(() => expect(queryClient.getQueryCache().getAll()).toEqual([]));
    // The address bar still says where the person was.
    expect(window.location.pathname).toBe('/orders/o1');

    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('heading', { name: 'Order 10001' })).toBeTruthy();
    await waitFor(() => expect(tabNames()).toEqual(['Dashboard', 'Order 10001', 'Customers']));
    expect(selected()).toBe('Order 10001');
    expect(window.location.pathname).toBe('/orders/o1');
    expect(screen.queryByText(/session has expired/)).toBeNull();

    // The tabs behind it work as they did.
    fireEvent.click(screen.getByRole('tab', { name: 'Customers' }));
    expect(await screen.findByRole('heading', { name: 'Customers' })).toBeTruthy();
    expect(window.location.pathname).toBe('/customers');
  });

  it('signs out when asked, after the access token has expired too, without calling it an expiry', async () => {
    const server = fakeServer();
    render(<App queryClient={createQueryClient()} />);
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeTruthy();

    server.valid = 't1-renewed';
    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeTruthy();
    expect(screen.queryByText(/session has expired/)).toBeNull();
    expect(screen.getByLabelText('Email')).toHaveProperty('value', '');
  });

  it('starts on the login page, saying nothing about an expiry, when there was no session to restore', async () => {
    const server = fakeServer();
    server.refreshTokenGood = false;
    render(<App queryClient={createQueryClient()} />);

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeTruthy();
    expect(screen.queryByText(/session has expired/)).toBeNull();
    expect(screen.getByLabelText('Email')).toHaveProperty('value', '');
  });
});
