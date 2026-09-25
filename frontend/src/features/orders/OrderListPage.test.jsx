import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { channelsFixture, orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function answer({ orders = [orderFixture()], total = orders.length } = {}) {
  api.mockImplementation(async (path) => {
    if (path.startsWith('/api/channels')) return channelsFixture;
    if (path.startsWith('/api/order-tags')) return { member: [{ name: 'VIP', orders: 3 }, { name: 'gift wrap', orders: 1 }] };
    if (path === '/api/orders/bulk-tags') return null;
    if (path.startsWith('/api/orders?')) return { member: orders, totalItems: total };
    throw new Error(`Unexpected ${path}`);
  });
}

const lastListQuery = () => new URLSearchParams(api.mock.calls.map(([path]) => path).filter((path) => path.startsWith('/api/orders?')).at(-1).split('?')[1]);

describe('the order list', () => {
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
    expect(screen.getByRole('button', { name: 'Status On hold' }).getAttribute('aria-expanded')).toBe('false');
  });

  it('filters by status and date and keeps it in the URL', async () => {
    answer();
    const router = renderAt('/orders');
    await screen.findByRole('link', { name: '10001' });

    fireEvent.click(screen.getByRole('button', { name: 'Status All' }));
    const statuses = screen.getByRole('group', { name: 'Status' });
    fireEvent.click(within(statuses).getByRole('checkbox', { name: 'Shipped' }));
    await waitFor(() => expect(lastListQuery().get('status')).toBe('shipped'));
    fireEvent.click(within(statuses).getByRole('checkbox', { name: 'Pending' }));
    await waitFor(() => expect(lastListQuery().get('status')).toBe('pending,shipped'));
    expect(screen.getByRole('button', { name: 'Status Pending, Shipped' })).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Placed from'), { target: { value: '2026-09-01' } });
    await waitFor(() => expect(lastListQuery().get('placedFrom')).toBe(new Date(2026, 8, 1).toISOString()));
    expect(router.state.location.search).toMatchObject({ 'f.status': 'pending,shipped', 'f.placedAt': '2026-09-01..' });
  });

  it('reads statuses and tags from older single-value links and the dashboard', async () => {
    answer();
    renderAt('/orders?f.status=confirmed,allocated,picking,packed&f.tags=VIP');

    await screen.findByRole('link', { name: '10001' });
    expect(lastListQuery().get('status')).toBe('confirmed,allocated,picking,packed');
    expect(lastListQuery().get('tag')).toBe('VIP');

    fireEvent.click(screen.getByRole('button', { name: 'Status Awaiting fulfillment' }));
    const statuses = screen.getByRole('group', { name: 'Status' });
    expect(within(statuses).getByRole('button', { name: 'Awaiting fulfillment' }).getAttribute('aria-pressed')).toBe('true');
    expect(within(statuses).getByRole('checkbox', { name: 'Picking' }).checked).toBe(true);
    expect(within(statuses).getByRole('checkbox', { name: 'Pending' }).checked).toBe(false);

    fireEvent.click(within(statuses).getByRole('button', { name: 'Clear' }));
    await waitFor(() => expect(lastListQuery().has('status')).toBe(false));
    // The tag from the link shows, and stays chosen, before the tag list has loaded.
    fireEvent.click(screen.getByRole('button', { name: 'Tags VIP' }));
    expect(within(screen.getByRole('group', { name: 'Tags' })).getByRole('checkbox', { name: 'VIP' }).checked).toBe(true);
  });

  it('filters by the day orders shipped without showing a column for it', async () => {
    answer();
    renderAt('/orders?f.shippedAt=2026-09-26..2026-09-26');

    await screen.findByRole('link', { name: '10001' });
    expect(lastListQuery().get('shippedFrom')).toBe(new Date(2026, 8, 26).toISOString());
    expect(lastListQuery().get('shippedBefore')).toBe(new Date(2026, 8, 27).toISOString());
    expect(screen.getByLabelText('Shipped from').value).toBe('2026-09-26');
    expect(screen.queryByRole('columnheader', { name: /Shipped/ })).toBeNull();
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

  it('shows the payment status and tags, and filters by them', async () => {
    answer({ orders: [orderFixture({ paymentStatus: 'paid', tags: ['VIP', 'gift wrap'] })] });
    renderAt('/orders', { locale: 'sv' });

    const row = (await screen.findByRole('link', { name: '10001' })).closest('tr');
    expect(within(row).getByText('Betald')).toBeTruthy();
    expect(within(row).getByText('VIP')).toBeTruthy();
    expect(within(row).getByText('gift wrap')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Taggar Alla' }));
    const tags = screen.getByRole('group', { name: 'Taggar' });
    fireEvent.click(await within(tags).findByRole('checkbox', { name: 'gift wrap' }));
    await waitFor(() => expect(lastListQuery().get('tag')).toBe('gift wrap'));
    fireEvent.click(within(tags).getByRole('checkbox', { name: 'VIP' }));
    // Any of the tags, in the order the list shows them.
    await waitFor(() => expect(lastListQuery().get('tag')).toBe('VIP,gift wrap'));
    fireEvent.keyDown(within(tags).getByRole('checkbox', { name: 'VIP' }), { key: 'Escape' });
    expect(screen.queryByRole('group', { name: 'Taggar' })).toBeNull();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Taggar VIP, gift wrap' }));

    fireEvent.change(screen.getByLabelText('Betalning'), { target: { value: 'refunded' } });
    await waitFor(() => expect(lastListQuery().get('paymentStatus')).toBe('refunded'));
  });

  it('tags the selected orders from the bulk-action bar', async () => {
    answer({ orders: [orderFixture(), orderFixture({ '@id': '/api/orders/o2', id: 'o2', number: '10002' })] });
    renderAt('/orders');
    await screen.findByRole('link', { name: '10001' });

    fireEvent.click(screen.getByRole('checkbox', { name: /10001/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /10002/ }));
    const bar = screen.getByRole('region', { name: 'Bulk actions' });
    fireEvent.click(within(bar).getByRole('button', { name: 'Add tag' }));

    const dialog = screen.getByRole('dialog', { name: 'Add a tag to the selected orders (2)' });
    fireEvent.change(within(dialog).getByLabelText('Tag'), { target: { value: 'a,b' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add tag' }));
    expect(within(dialog).getByText(/without commas/)).toBeTruthy();

    fireEvent.change(within(dialog).getByLabelText('Tag'), { target: { value: '  Rush ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add tag' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/bulk-tags', { method: 'POST', body: { orders: ['o1', 'o2'], add: ['Rush'] } }));
    expect(await screen.findByText('Tag “Rush” added to the selected orders (2).')).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Bulk actions' })).toBeNull();
  });

  it('offers a viewer no bulk actions', async () => {
    answer();
    renderAt('/orders', { user: viewer });

    await screen.findByRole('link', { name: '10001' });
    expect(screen.queryByRole('checkbox', { name: /10001/ })).toBeNull();
  });
});
