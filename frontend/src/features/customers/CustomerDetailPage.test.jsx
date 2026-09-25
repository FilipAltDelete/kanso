import { screen, within } from '@testing-library/react';
import { anna, mockApi, renderCustomers } from './testing.jsx';

describe('the customer page', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('shows the customer, their addresses, the history and a place for orders', async () => {
    mockApi({
      [`GET /api/customers/${anna.id}`]: anna,
      [`GET /api/customers/${anna.id}/history`]: {
        totalItems: 2,
        member: [
          {
            id: 'e2',
            type: 'updated',
            actor: 'ops@example.com',
            changes: { name: { before: 'Anna S', after: 'Anna Svensson' }, addresses: { before: [], after: [] } },
            occurredAt: '2026-09-21T09:30:00+00:00',
          },
          { id: 'e1', type: 'created', actor: 'API key: Shopify sync', changes: {}, occurredAt: '2026-09-20T08:00:00+00:00' },
        ],
      },
    });

    renderCustomers(`/customers/${anna.id}`);

    expect(await screen.findByRole('heading', { name: 'Anna Svensson' })).toBeTruthy();
    expect(screen.getByText('Storgatan 1')).toBeTruthy();
    expect(screen.getByText('Default')).toBeTruthy();
    expect(await screen.findByText('Name: Anna S → Anna Svensson')).toBeTruthy();
    expect(screen.getByText('Addresses changed')).toBeTruthy();
    expect(screen.getByText('by API key: Shopify sync')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Orders' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Edit' })).toBeTruthy();
  });

  it('says so when the customer does not exist', async () => {
    mockApi({});

    renderCustomers('/customers/nope');

    expect(await screen.findByText('This customer does not exist.')).toBeTruthy();
  });

  it("lists the customer's orders, newest first, and offers a new one to operators", async () => {
    const calls = mockApi({
      [`GET /api/customers/${anna.id}`]: anna,
      [`GET /api/customers/${anna.id}/history`]: { member: [], totalItems: 0 },
      'GET /api/orders': {
        member: [
          {
            id: 'o2',
            number: '10002',
            status: 'confirmed',
            channel: { code: 'manual', name: 'Manual' },
            currency: 'SEK',
            total: 19950,
            customer: { id: anna.id, name: 'Anna Svensson', email: 'anna@example.com' },
            lineCount: 1,
            placedAt: '2026-09-22T10:00:00+00:00',
            updatedAt: '2026-09-22T10:00:00+00:00',
            version: 2,
          },
        ],
        totalItems: 1,
      },
    });

    renderCustomers(`/customers/${anna.id}`);

    const orders = await screen.findByRole('table', { name: 'Orders' });
    expect(within(orders).getByRole('link', { name: '10002' })).toBeTruthy();
    expect(within(orders).getByText('Confirmed')).toBeTruthy();
    const request = new URL(calls.find((call) => call.url.startsWith('/api/orders')).url, 'http://localhost').searchParams;
    expect(request.get('customer')).toBe(anna.id);
    expect(request.get('sort')).toBe('-placedAt');
    expect(screen.getByRole('link', { name: 'New order' }).getAttribute('href')).toBe(`/orders/new?customer=${anna.id}`);
  });

  it('says so when the customer has no orders, and offers a viewer no new one', async () => {
    mockApi({
      [`GET /api/customers/${anna.id}`]: anna,
      [`GET /api/customers/${anna.id}/history`]: { member: [], totalItems: 0 },
      'GET /api/orders': { member: [], totalItems: 0 },
    });

    renderCustomers(`/customers/${anna.id}`, { roles: ['ROLE_VIEWER'] });

    expect(await screen.findByText('No orders for this customer yet.')).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'New order' })).toBeNull();
  });
});
