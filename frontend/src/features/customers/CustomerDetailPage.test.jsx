import { screen } from '@testing-library/react';
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
});
