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
});
