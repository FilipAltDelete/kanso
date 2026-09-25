import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const location = { id: 'loc1', code: 'WH1', name: 'Main' };

/** Confirmed and reserved: TSHIRT-M 3 (1 already shipped), SOCKS 2. */
function confirmed(overrides = {}) {
  const [tee, socks] = orderFixture().lines;

  return orderFixture({
    status: 'confirmed',
    version: 3,
    location,
    canShip: true,
    availableTransitions: ['allocate', 'hold'],
    lines: [
      { ...tee, reservedQuantity: 2, shippedQuantity: 1 },
      { ...socks, reservedQuantity: 2, shippedQuantity: 0 },
    ],
    shipments: [
      {
        id: 's1',
        location: { code: 'WH1', name: 'Main' },
        carrier: 'PostNord',
        trackingNumber: '00370712345',
        shippedAt: '2026-09-26T09:00:00+00:00',
        actor: { id: 'u1', name: 'Olle' },
        voidable: true,
        lines: [{ lineId: 'l1', position: 1, sku: 'TSHIRT-M', name: 'T-shirt, M', quantity: 1 }],
      },
    ],
    ...overrides,
  });
}

describe('shipping an order', () => {
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

  beforeEach(() => api.mockReset());

  it('shows what has shipped, per line and parcel by parcel', async () => {
    api.mockResolvedValue(confirmed());
    renderAt('/orders/o1');

    const lines = await screen.findByRole('table', { name: 'Lines' });
    const headers = within(lines).getAllByRole('columnheader').map((cell) => cell.textContent);
    const shipped = headers.indexOf('Shipped');
    expect(within(lines).getAllByRole('row').slice(1, 3).map((row) => row.querySelectorAll('td')[shipped].textContent)).toEqual(['1', '0']);

    const shipments = screen.getByRole('region', { name: 'Shipments' });
    expect(within(shipments).getByText('PostNord')).toBeTruthy();
    expect(within(shipments).getByText('00370712345')).toBeTruthy();
    expect(within(shipments).getByText('1 × TSHIRT-M')).toBeTruthy();
  });

  it('ships part of a line with the order version, carrier and tracking number', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Ship' }));
    const dialog = screen.getByRole('dialog', { name: 'Ship order 10001' });
    // Everything left, to start with.
    expect(within(dialog).getByLabelText('Units of TSHIRT-M to ship now')).toHaveProperty('value', '2');
    expect(within(dialog).getByLabelText('Units of SOCKS to ship now')).toHaveProperty('value', '2');

    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to ship now'), { target: { value: '1' } });
    fireEvent.change(within(dialog).getByLabelText('Units of SOCKS to ship now'), { target: { value: '0' } });
    fireEvent.change(within(dialog).getByLabelText('Carrier'), { target: { value: 'DHL' } });
    fireEvent.change(within(dialog).getByLabelText('Tracking number'), { target: { value: ' JD0123 ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ship 1 units' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/shipments', expect.objectContaining({ method: 'POST' })));
    const [, { body }] = api.mock.calls.find(([path]) => path === '/api/orders/o1/shipments');
    expect(body).toMatchObject({ version: 3, lines: [{ lineId: 'l1', quantity: 1 }], carrier: 'DHL', trackingNumber: 'JD0123' });
    // When it left: now, unless changed, sent as an instant.
    expect(Math.abs(new Date(body.shippedAt).getTime() - Date.now())).toBeLessThan(2 * 60 * 1000);
  });

  it('sends the time the parcel left when it was earlier', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Ship' }));
    const dialog = screen.getByRole('dialog', { name: 'Ship order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Shipped at'), { target: { value: '2026-09-26T07:30' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ship 4 units' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/shipments', expect.objectContaining({ method: 'POST' })));
    const [, { body }] = api.mock.calls.find(([path]) => path === '/api/orders/o1/shipments');
    expect(body.shippedAt).toBe(new Date('2026-09-26T07:30').toISOString());
  });

  it('refuses more than is left, and a shipment of nothing, before sending', async () => {
    api.mockResolvedValue(confirmed());
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Ship' }));
    const dialog = screen.getByRole('dialog', { name: 'Ship order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to ship now'), { target: { value: '3' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /^Ship \d+ units$/ }));
    expect(within(dialog).getByText('Only 2 left.')).toBeTruthy();

    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to ship now'), { target: { value: '0' } });
    fireEvent.change(within(dialog).getByLabelText('Units of SOCKS to ship now'), { target: { value: '0' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ship 0 units' }));
    expect(within(dialog).getByText('Ship at least one unit.')).toBeTruthy();

    expect(api).toHaveBeenCalledTimes(1);
  });

  it('stays open and says so when the order changed meanwhile', async () => {
    api
      .mockResolvedValueOnce(confirmed())
      .mockRejectedValueOnce(Object.assign(new Error('Conflict'), { status: 409, violations: [{ path: 'version', message: 'x', code: 'stale_version' }] }))
      .mockResolvedValue(confirmed({ version: 5 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Ship' }));
    const dialog = screen.getByRole('dialog', { name: 'Ship order 10001' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ship 4 units' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('Someone changed this order');
    expect(screen.getByRole('dialog', { name: 'Ship order 10001' })).toBeTruthy();
  });

  it('offers no Ship button to a viewer, or when nothing can ship', async () => {
    api.mockResolvedValue(confirmed());
    renderAt('/orders/o1', { user: viewer });
    await screen.findByRole('table', { name: 'Lines' });
    expect(screen.queryByRole('button', { name: 'Ship' })).toBeNull();
  });

  it('offers no Ship button once everything has shipped', async () => {
    api.mockResolvedValue(confirmed({ status: 'shipped', canShip: false, availableTransitions: ['deliver'] }));
    renderAt('/orders/o1');
    await screen.findByRole('table', { name: 'Lines' });
    expect(screen.queryByRole('button', { name: 'Ship' })).toBeNull();
  });

  it('corrects a tracking number with the order version', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    const shipments = await screen.findByRole('region', { name: 'Shipments' });
    fireEvent.click(within(shipments).getByRole('button', { name: 'Correct tracking' }));
    fireEvent.change(within(shipments).getByLabelText('Tracking number'), { target: { value: '00370712399' } });
    fireEvent.click(within(shipments).getByRole('button', { name: 'Save' }));

    await waitFor(() =>
      expect(api).toHaveBeenCalledWith('/api/orders/o1/shipments/s1/tracking', {
        method: 'POST',
        body: { version: 3, carrier: 'PostNord', trackingNumber: '00370712399' },
      }),
    );
  });

  it('voids a shipment after asking, with the reason', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    const shipments = await screen.findByRole('region', { name: 'Shipments' });
    fireEvent.click(within(shipments).getByRole('button', { name: 'Void shipment' }));
    const confirm = within(shipments).getByRole('group', { name: 'Void shipment' });
    expect(confirm.textContent).toContain('not for a return');
    expect(api).toHaveBeenCalledTimes(1);
    fireEvent.change(within(confirm).getByLabelText('Reason (optional)'), { target: { value: 'Recorded twice' } });
    fireEvent.click(within(confirm).getByRole('button', { name: 'Void shipment' }));

    await waitFor(() =>
      expect(api).toHaveBeenCalledWith('/api/orders/o1/shipments/s1/void', { method: 'POST', body: { version: 3, reason: 'Recorded twice' } }),
    );
  });

  it('shows a voided shipment as void, with nothing more to do to it', async () => {
    const [shipment] = confirmed().shipments;
    api.mockResolvedValue(confirmed({ shipments: [{ ...shipment, voidedAt: '2026-09-26T10:00:00+00:00', voidedBy: { id: 'u1', name: 'Olle' }, voidReason: 'Recorded twice', voidable: false }] }));
    renderAt('/orders/o1');

    const shipments = await screen.findByRole('region', { name: 'Shipments' });
    expect(within(shipments).getByText('Voided')).toBeTruthy();
    expect(within(shipments).getByText(/Recorded twice/)).toBeTruthy();
    expect(within(shipments).queryByRole('button', { name: 'Void shipment' })).toBeNull();
    expect(within(shipments).queryByRole('button', { name: 'Packing slip' })).toBeNull();
  });

  it('offers each live shipment its own packing slip', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce({
      id: 'd1', type: 'packing_slip', orderId: 'o1', orderNumber: '10001', orderVersion: 3, shipmentId: 's1', locale: 'en',
      status: 'queued', filename: 'packing-slip-10001-1.pdf', downloadUrl: null, byteSize: null, createdAt: '2026-09-26T10:00:00+00:00', completedAt: null,
    });
    renderAt('/orders/o1');

    const shipments = await screen.findByRole('region', { name: 'Shipments' });
    fireEvent.click(within(shipments).getByRole('button', { name: 'Packing slip' }));

    await waitFor(() =>
      expect(api).toHaveBeenCalledWith('/api/orders/o1/documents', { method: 'POST', body: { type: 'packing_slip', locale: 'en', shipmentId: 's1' } }),
    );
  });
});
