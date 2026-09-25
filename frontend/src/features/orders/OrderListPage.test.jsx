import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { channelsFixture, orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function answer({ orders = [orderFixture()], total = orders.length } = {}) {
  api.mockImplementation(async (path) => {
    if (path.startsWith('/api/channels')) return channelsFixture;
    if (path.startsWith('/api/orders?')) return { member: orders, totalItems: total };
    throw new Error(`Unexpected ${path}`);
  });
}

const lastListQuery = () => new URLSearchParams(api.mock.calls.map(([path]) => path).filter((path) => path.startsWith('/api/orders?')).at(-1).split('?')[1]);

describe('the order list', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('shows orders newest first, with money and status in the viewer’s language', async () => {
    answer();
    renderAt('/orders', { locale: 'sv' });

    const link = await screen.findByRole('link', { name: '10001' });
    const row = link.closest('tr');
    expect(within(row).getByText('Anna Andersson')).toBeTruthy();
    expect(within(row).getByText('Väntande')).toBeTruthy();
    expect(within(row).getByText(/696,50\s*kr/)).toBeTruthy();
    expect(lastListQuery().get('sort')).toBe('-placedAt');
    expect(screen.getByRole('link', { name: 'Ny order' })).toBeTruthy();
  });

  it('asks the server for the view in the URL', async () => {
    answer({ total: 120 });
    renderAt('/orders?f.status=on_hold&f.channel=manual&q=anna&page=2&sort=total');

    await screen.findByRole('link', { name: '10001' });
    expect(Object.fromEntries(lastListQuery())).toEqual({ page: '2', itemsPerPage: '25', sort: 'total', q: 'anna', status: 'on_hold', channel: 'manual' });
    expect(screen.getByText('26–26 of 120')).toBeTruthy();
    expect(screen.getByLabelText('Status').value).toBe('on_hold');
  });

  it('filters by status and date and keeps it in the URL', async () => {
    answer();
    const router = renderAt('/orders');
    await screen.findByRole('link', { name: '10001' });

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'shipped' } });
    await waitFor(() => expect(lastListQuery().get('status')).toBe('shipped'));

    fireEvent.change(screen.getByLabelText('Placed from'), { target: { value: '2026-09-01' } });
    await waitFor(() => expect(lastListQuery().get('placedFrom')).toBe(new Date(2026, 8, 1).toISOString()));
    expect(router.state.location.search).toMatchObject({ 'f.status': 'shipped', 'f.placedAt': '2026-09-01..' });
  });

  it('opens an order with Enter', async () => {
    answer();
    const router = renderAt('/orders');
    const link = await screen.findByRole('link', { name: '10001' });

    const customerCell = within(link.closest('tr')).getByText('Anna Andersson');
    customerCell.focus();
    fireEvent.keyDown(customerCell, { key: 'Enter' });

    await waitFor(() => expect(router.state.location.pathname).toBe('/orders/o1'));
  });

  it('offers no new-order button to a viewer', async () => {
    answer();
    renderAt('/orders', { user: viewer });

    await screen.findByRole('link', { name: '10001' });
    expect(screen.queryByRole('link', { name: 'New order' })).toBeNull();
  });

  it('says when there are no orders', async () => {
    answer({ orders: [] });
    renderAt('/orders');

    expect(await screen.findByText(/No orders yet/)).toBeTruthy();
  });
});
