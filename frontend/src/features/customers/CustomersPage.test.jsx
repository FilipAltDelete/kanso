import { screen, waitFor } from '@testing-library/react';
import { anna, mockApi, renderCustomers } from './testing.jsx';

describe('the customer list', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('asks the API for the view in the URL and lists what comes back', async () => {
    const calls = mockApi({ 'GET /api/customers': { member: [anna], totalItems: 1 } });

    renderCustomers('/customers?q=anna&sort=name');

    expect(await screen.findByRole('link', { name: 'Anna Svensson' })).toBeTruthy();
    expect(screen.getByText('Stockholm, Sweden')).toBeTruthy();

    await waitFor(() => expect(calls.length).toBeGreaterThan(0));
    const params = new URL(calls.at(-1).url, 'http://localhost').searchParams;
    expect(params.get('q')).toBe('anna');
    expect(params.get('sort')).toBe('name');
    expect(params.get('page')).toBe('1');
  });

  it('offers a new customer only to those who can create one', async () => {
    mockApi({ 'GET /api/customers': { member: [], totalItems: 0 } });

    renderCustomers('/customers', { roles: ['ROLE_VIEWER'] });

    expect(await screen.findByText('No customers yet.')).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'New customer' })).toBeNull();
  });

  it('is in Swedish when the UI is', async () => {
    mockApi({ 'GET /api/customers': { member: [anna], totalItems: 1 } });

    renderCustomers('/customers', { locale: 'sv' });

    expect(await screen.findByRole('heading', { name: 'Kunder' })).toBeTruthy();
    expect(await screen.findByText('Stockholm, Sverige')).toBeTruthy();
  });
});
