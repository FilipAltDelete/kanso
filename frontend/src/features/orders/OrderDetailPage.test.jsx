import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function conflict(code) {
  return Object.assign(new Error('Conflict'), { status: 409, violations: [{ path: code === 'stale_version' ? 'version' : 'transition', message: 'x', code }] });
}

describe('the order detail', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('shows the lines, addresses, total and history', async () => {
    api.mockResolvedValue(
      orderFixture({
        status: 'confirmed',
        version: 2,
        events: [
          ...orderFixture().events,
          { id: 'e2', type: 'transition', transition: 'confirm', actor: { id: 'u1', name: 'Olle' }, before: { status: 'pending' }, after: { status: 'confirmed' }, occurredAt: '2026-09-26T09:00:00+00:00' },
        ],
      }),
    );
    renderAt('/orders/o1', { locale: 'sv' });

    expect(await screen.findByRole('heading', { name: 'Order 10001' })).toBeTruthy();
    const lines = screen.getByRole('table', { name: 'Rader' });
    expect(within(lines).getByText('TSHIRT-M')).toBeTruthy();
    expect(within(lines).getByText(/598,50\s*kr/)).toBeTruthy();
    expect(within(lines).getByText(/696,50\s*kr/)).toBeTruthy();
    expect(screen.getByText('Storgatan 1')).toBeTruthy();

    const history = within(screen.getByRole('region', { name: 'Historik' })).getAllByRole('listitem');
    expect(history.map((item) => item.querySelector('p').textContent)).toEqual(['Bekräfta: Väntande → Bekräftad', 'Ordern skapades']);
  });

  it('moves the order with the version it was shown at', async () => {
    api.mockResolvedValueOnce(orderFixture()).mockResolvedValueOnce(orderFixture({ status: 'confirmed', version: 2, availableTransitions: ['allocate', 'cancel', 'hold'] }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Confirm' }));

    await screen.findByRole('button', { name: 'Allocate' });
    expect(api).toHaveBeenCalledWith('/api/orders/o1/transitions', { method: 'POST', body: { transition: 'confirm', version: 1 } });
  });

  it('asks before cancelling', async () => {
    api.mockResolvedValueOnce(orderFixture()).mockResolvedValueOnce(orderFixture({ status: 'cancelled', version: 2, availableTransitions: [] }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel order' }));
    expect(api).toHaveBeenCalledTimes(1);
    const confirm = screen.getByRole('group', { name: 'Cancel order' });
    expect(within(confirm).getByText(/cannot be undone/)).toBeTruthy();

    fireEvent.click(within(confirm).getByRole('button', { name: 'Cancel order' }));
    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/transitions', { method: 'POST', body: { transition: 'cancel', version: 1 } }));
  });

  it('reloads and explains when someone else changed the order', async () => {
    api
      .mockResolvedValueOnce(orderFixture())
      .mockRejectedValueOnce(conflict('stale_version'))
      .mockResolvedValueOnce(orderFixture({ status: 'on_hold', heldFrom: 'pending', version: 2, availableTransitions: ['cancel', 'release'] }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Confirm' }));

    expect((await screen.findByRole('alert')).textContent).toMatch(/Someone else changed this order/);
    expect(await screen.findByRole('button', { name: 'Release hold' })).toBeTruthy();
    expect(screen.getByText('Held while Pending')).toBeTruthy();
  });

  it('shows a viewer no actions', async () => {
    api.mockResolvedValue(orderFixture());
    renderAt('/orders/o1', { user: viewer });

    await screen.findByRole('heading', { name: 'Order 10001' });
    expect(screen.queryByRole('button', { name: 'Confirm' })).toBeNull();
  });

  it('says when the order does not exist', async () => {
    api.mockRejectedValue(Object.assign(new Error('Not Found'), { status: 404 }));
    renderAt('/orders/nope');

    expect(await screen.findByText('There is no such order.')).toBeTruthy();
  });

  it('shows where the order ships from and what each line holds in stock', async () => {
    api.mockResolvedValue(
      orderFixture({
        status: 'confirmed',
        location: { id: 'loc1', code: 'WH1', name: 'Main' },
        lines: orderFixture().lines.map((line) => ({ ...line, reservedQuantity: line.quantity })),
      }),
    );
    renderAt('/orders/o1');

    expect(await screen.findByText(/Ships from WH1 · Main/)).toBeTruthy();
    const lines = screen.getByRole('table', { name: 'Lines' });
    const reserved = within(lines).getAllByRole('columnheader').map((cell) => cell.textContent).indexOf('Reserved');
    expect(reserved).toBeGreaterThan(-1);
    const rows = within(lines).getAllByRole('row').slice(1, 3);
    expect(rows.map((row) => row.querySelectorAll('td')[reserved].textContent)).toEqual(['3', '2']);
  });

  it('says which lines are short when confirming fails on stock', async () => {
    api.mockResolvedValueOnce(orderFixture({ location: { id: 'loc1', code: 'WH1', name: 'Main' } })).mockRejectedValueOnce(
      Object.assign(new Error('Not enough stock'), {
        status: 409,
        violations: [{ path: 'lines[1].quantity', message: 'SOCKS: 1 available at WH1, 2 needed.', code: 'insufficient_stock' }],
      }),
    );
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Confirm' }));

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Not enough stock at WH1');
    expect(alert.textContent).toContain('SOCKS');
    expect(alert.textContent).not.toContain('TSHIRT-M');
  });

  it('tells notes, tag changes and payment changes apart in the history', async () => {
    const at = '2026-09-26T09:00:00+00:00';
    api.mockResolvedValue(
      orderFixture({
        events: [
          ...orderFixture().events,
          { id: 'e2', type: 'tags_changed', actor: { id: 'u1', name: 'Olle' }, before: { tags: ['rush'] }, after: { tags: ['VIP'] }, occurredAt: at },
          { id: 'e3', type: 'payment_status_changed', actor: { id: 'u1', name: 'Olle' }, before: { paymentStatus: 'unpaid' }, after: { paymentStatus: 'paid' }, occurredAt: at },
          { id: 'e4', type: 'note', actor: { id: 'u3', name: 'Siv' }, after: { note: 'Ring innan leverans.\nPort 2.' }, occurredAt: at },
        ],
      }),
    );
    renderAt('/orders/o1', { locale: 'sv' });

    await screen.findByRole('heading', { name: 'Order 10001' });
    const history = within(screen.getByRole('region', { name: 'Historik' })).getAllByRole('listitem');
    expect(history.map((item) => item.querySelector('p').textContent)).toEqual([
      'Anteckning',
      'Betalning: Obetald → Betald',
      'Taggar tillagda: VIP · Taggar borttagna: rush',
      'Ordern skapades',
    ]);
    expect(history[0].querySelector('blockquote').textContent).toBe('Ring innan leverans.\nPort 2.');
    expect(history[0].textContent).toContain('Siv');
  });

  it('adds a note in any status', async () => {
    const cancelled = orderFixture({ status: 'cancelled', availableTransitions: [] });
    api.mockResolvedValueOnce(cancelled).mockResolvedValueOnce({
      ...cancelled,
      events: [...cancelled.events, { id: 'e2', type: 'note', actor: { id: 'u1', name: 'Olle' }, after: { note: 'Kunden ångrade sig.' }, occurredAt: '2026-09-26T09:00:00+00:00' }],
    });
    renderAt('/orders/o1');

    const field = await screen.findByLabelText('Add a note');
    fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
    expect(screen.getByText('Write the note first.')).toBeTruthy();
    expect(api).toHaveBeenCalledTimes(1);

    fireEvent.change(field, { target: { value: ' Kunden ångrade sig. ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add note' }));

    expect(await screen.findByText('Kunden ångrade sig.')).toBeTruthy();
    expect(api).toHaveBeenCalledWith('/api/orders/o1/notes', { method: 'POST', body: { note: 'Kunden ångrade sig.' } });
    expect(field.value).toBe('');
  });

  it('adds and removes tags', async () => {
    let tags = ['VIP'];
    api.mockImplementation(async (path, { body } = {}) => {
      if (path === '/api/order-tags') return { member: [{ name: 'VIP', orders: 2 }, { name: 'Rush', orders: 1 }] };
      if (path === '/api/orders/o1/tags') tags = [...tags, ...(body.add ?? [])].filter((tag) => !(body.remove ?? []).includes(tag)).sort();
      return orderFixture({ tags });
    });
    renderAt('/orders/o1');

    const field = await screen.findByLabelText('New tag');
    fireEvent.focus(field);
    fireEvent.change(field, { target: { value: 'Rush' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add tag' }));
    await screen.findByRole('button', { name: 'Remove tag Rush' });
    expect(api).toHaveBeenCalledWith('/api/orders/o1/tags', { method: 'POST', body: { add: ['Rush'] } });

    fireEvent.click(screen.getByRole('button', { name: 'Remove tag VIP' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Remove tag VIP' })).toBeNull());
    expect(api).toHaveBeenCalledWith('/api/orders/o1/tags', { method: 'POST', body: { remove: ['VIP'] } });
  });

  it('sets the payment status with the version it was shown at', async () => {
    api.mockResolvedValueOnce(orderFixture({ version: 3 })).mockResolvedValueOnce(orderFixture({ version: 4, paymentStatus: 'authorized' }));
    renderAt('/orders/o1');

    const select = await screen.findByLabelText('Payment status');
    expect(screen.getByText(/never card details/)).toBeTruthy();
    const save = screen.getByRole('button', { name: 'Save payment status' });
    expect(save.disabled).toBe(true);

    fireEvent.change(select, { target: { value: 'authorized' } });
    fireEvent.click(save);

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/payment-status', { method: 'POST', body: { paymentStatus: 'authorized', version: 3 } }));
    await waitFor(() => expect(save.disabled).toBe(true));
    expect(select.value).toBe('authorized');
  });

  it('shows a viewer the payment status and tags without controls', async () => {
    api.mockResolvedValue(orderFixture({ paymentStatus: 'partially_refunded', tags: ['VIP'] }));
    renderAt('/orders/o1', { user: viewer });

    await screen.findByRole('heading', { name: 'Order 10001' });
    expect(screen.getByText('Partly refunded')).toBeTruthy();
    expect(screen.getByText('VIP')).toBeTruthy();
    expect(screen.queryByLabelText('Payment status')).toBeNull();
    expect(screen.queryByLabelText('New tag')).toBeNull();
    expect(screen.queryByLabelText('Add a note')).toBeNull();
  });
});
