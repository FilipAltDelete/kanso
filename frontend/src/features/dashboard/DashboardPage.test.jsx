import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { DASHBOARD_REFRESH_MS } from '../../api/dashboard.js';
import { renderAt } from '../orders/testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function dashboardFixture(overrides = {}) {
  return {
    '@id': '/api/dashboard',
    date: '2026-09-26',
    timeZone: 'Europe/Stockholm',
    dayStart: '2026-09-25T22:00:00+00:00',
    dayEnd: '2026-09-26T22:00:00+00:00',
    ordersToday: 12,
    awaitingFulfillment: 1234,
    shippedToday: 5,
    ordersByStatus: { pending: 3, confirmed: 4, allocated: 0, picking: 1, packed: 2, shipped: 7, delivered: 20, cancelled: 1, on_hold: 0 },
    stockOuts: {
      count: 11,
      items: [{ productId: 'p1', sku: 'SOCKS', productName: 'Strumpor', locationId: 'l1', locationCode: 'WH1', locationName: 'Huvudlager', onHand: 2, reserved: 2 }],
    },
    generatedAt: '2026-09-26T08:30:00+00:00',
    ...overrides,
  };
}

describe('the dashboard', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('shows the day in numbers, each opening the list it counts', async () => {
    api.mockResolvedValue(dashboardFixture());
    const router = renderAt('/', { locale: 'sv' });

    const today = await screen.findByRole('link', { name: /Ordrar idag\s*12/ });
    expect(screen.getByRole('link', { name: /Väntar på plock\s*1\s234/ })).toBeTruthy();
    expect(screen.getByRole('link', { name: /Skickade idag\s*5/ })).toBeTruthy();
    expect(screen.getByRole('link', { name: /Slut i lager\s*11/ }).getAttribute('href')).toBe('/#stock-outs');

    expect(api.mock.calls[0][0]).toMatch(/^\/api\/dashboard\?timeZone=/);

    fireEvent.click(today);
    await waitFor(() => expect(router.state.location.pathname).toBe('/orders'));
    expect(router.state.location.search).toEqual({ 'f.placedAt': '2026-09-26..2026-09-26' });
  });

  it('links the fulfillment queue to the orders in those statuses', async () => {
    api.mockResolvedValue(dashboardFixture());
    const router = renderAt('/');

    fireEvent.click(await screen.findByRole('link', { name: /Awaiting fulfillment/ }));

    await waitFor(() => expect(router.state.location.search).toEqual({ 'f.status': 'confirmed,allocated,picking,packed' }));
  });

  it('lists orders by status and the stock-outs', async () => {
    api.mockResolvedValue(dashboardFixture());
    renderAt('/');

    const byStatus = await screen.findByRole('region', { name: 'Orders by status' });
    expect(within(byStatus).getAllByRole('link')).toHaveLength(9);
    expect(within(byStatus).getByRole('link', { name: /Delivered\s*20/ })).toBeTruthy();

    const stockOuts = screen.getByRole('region', { name: 'Out of stock' });
    expect(within(stockOuts).getByRole('link', { name: 'Strumpor' }).getAttribute('href')).toBe('/products/p1');
    expect(within(stockOuts).getByText('And 10 more.')).toBeTruthy();
  });

  it('says so when there are no orders and nothing is out of stock', async () => {
    api.mockResolvedValue(
      dashboardFixture({
        ordersToday: 0,
        awaitingFulfillment: 0,
        shippedToday: 0,
        ordersByStatus: { pending: 0, confirmed: 0, allocated: 0, picking: 0, packed: 0, shipped: 0, delivered: 0, cancelled: 0, on_hold: 0 },
        stockOuts: { count: 0, items: [] },
      }),
    );
    renderAt('/');

    expect(await screen.findByText('No orders yet')).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Orders by status' })).toBeNull();
    expect(screen.getByText('Every stocked product has something available.')).toBeTruthy();
  });

  it('counts again every 30 seconds', async () => {
    expect(DASHBOARD_REFRESH_MS).toBe(30_000);
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      api.mockResolvedValueOnce(dashboardFixture()).mockResolvedValue(dashboardFixture({ ordersToday: 13 }));
      renderAt('/');

      expect(await screen.findByRole('link', { name: /Orders today\s*12/ })).toBeTruthy();
      await vi.advanceTimersByTimeAsync(DASHBOARD_REFRESH_MS);
      expect(await screen.findByRole('link', { name: /Orders today\s*13/ })).toBeTruthy();
    } finally {
      vi.useRealTimers();
    }
  });
});
