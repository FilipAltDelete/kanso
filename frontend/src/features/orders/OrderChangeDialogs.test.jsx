import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const location = { id: 'loc1', code: 'WH1', name: 'Main' };

/** Confirmed and reserved: TSHIRT-M 3, SOCKS 2; editable and cancellable. */
function confirmed(overrides = {}) {
  const [tee, socks] = orderFixture().lines;

  return orderFixture({
    status: 'confirmed',
    version: 3,
    location,
    canShip: true,
    canEdit: true,
    canCancelItems: true,
    availableTransitions: ['allocate', 'cancel', 'hold'],
    lines: [
      { ...tee, reservedQuantity: 3 },
      { ...socks, reservedQuantity: 2 },
    ],
    ...overrides,
  });
}

const conflict = (code, path = 'version') => Object.assign(new Error('Conflict'), { status: 409, violations: [{ path, message: 'x', code }] });

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

describe('editing an order', () => {
  it('sends only what changed: quantities, removed and added lines, customer and addresses', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit order' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit order 10001' });
    expect(within(dialog).getByLabelText('Quantity of TSHIRT-M')).toHaveProperty('value', '3');

    fireEvent.change(within(dialog).getByLabelText('Quantity of TSHIRT-M'), { target: { value: '5' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove SOCKS' }));
    expect(within(dialog).getByRole('button', { name: 'Keep SOCKS' }).getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add line' }));
    const added = within(dialog).getByRole('listitem', { name: 'New line 1' });
    fireEvent.change(within(added).getByLabelText('SKU'), { target: { value: ' CAP ' } });
    fireEvent.change(within(added).getByLabelText('Quantity'), { target: { value: '2' } });
    fireEvent.change(within(added).getByLabelText('Unit price'), { target: { value: '99.50' } });
    // 5 × 199.50 + 2 × 99.50
    expect(within(dialog).getByText('SEK 1,196.50')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText('Email'), { target: { value: '' } });
    fireEvent.change(within(dialog).getByLabelText('City', { selector: 'input' }), { target: { value: 'Lund' } });

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    await waitFor(() =>
      expect(api).toHaveBeenCalledWith('/api/orders/o1/edits', {
        method: 'POST',
        body: {
          version: 3,
          lines: [
            { lineId: 'l1', quantity: 5 },
            { lineId: 'l2', quantity: 0 },
            { sku: 'CAP', quantity: 2, unitPrice: 9950 },
          ],
          customer: { email: null },
          shippingAddress: { line1: 'Storgatan 1', postalCode: '111 22', city: 'Lund', countryCode: 'SE' },
        },
      }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Edit order 10001' })).toBeNull());
  });

  it('sends nothing when nothing changed, and refuses a quantity below what was cancelled', async () => {
    const [tee, socks] = confirmed().lines;
    api.mockResolvedValue(confirmed({ lines: [{ ...tee, cancelledQuantity: 2, reservedQuantity: 1 }, socks] }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit order' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit order 10001' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    expect(within(dialog).getByText('Nothing has been changed.')).toBeTruthy();

    fireEvent.change(within(dialog).getByLabelText('Quantity of TSHIRT-M'), { target: { value: '1' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    expect(within(dialog).getByText('At least 2.')).toBeTruthy();
    expect(api).toHaveBeenCalledTimes(1);
  });

  it('names the short SKUs when there is not enough stock', async () => {
    api.mockResolvedValueOnce(confirmed()).mockRejectedValueOnce(conflict('insufficient_stock', 'lines[0].quantity')).mockResolvedValue(confirmed());
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit order' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Quantity of SOCKS'), { target: { value: '20' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect((await within(dialog).findByRole('alert')).textContent).toBe('Not enough stock at WH1 for: SOCKS. Lower the quantities or adjust the stock.');
  });

  it('says so when the order changed meanwhile', async () => {
    api.mockResolvedValueOnce(confirmed()).mockRejectedValueOnce(conflict('stale_version')).mockResolvedValue(confirmed({ version: 5 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit order' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Customer name'), { target: { value: 'Anna Berg' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('Someone changed this order');
  });

  it('is not offered once picking has started, or to a viewer', async () => {
    api.mockResolvedValue(confirmed({ status: 'picking', canEdit: false }));
    renderAt('/orders/o1');
    await screen.findByRole('table', { name: 'Lines' });
    expect(screen.queryByRole('button', { name: 'Edit order' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Cancel items' })).toBeTruthy();
  });

  it('offers neither change to a viewer', async () => {
    api.mockResolvedValue(confirmed());
    renderAt('/orders/o1', { user: viewer });
    await screen.findByRole('table', { name: 'Lines' });
    expect(screen.queryByRole('button', { name: 'Edit order' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Cancel items' })).toBeNull();
  });
});

describe('cancelling items', () => {
  it('cancels some units of a line with the version and a reason', async () => {
    api.mockResolvedValueOnce(confirmed()).mockResolvedValueOnce(confirmed({ version: 4 }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel items' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel items on order 10001' });
    expect(within(dialog).getByLabelText('Units of TSHIRT-M to cancel')).toHaveProperty('value', '0');

    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to cancel'), { target: { value: '2' } });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: ' Damaged ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel 2 units' }));

    await waitFor(() =>
      expect(api).toHaveBeenCalledWith('/api/orders/o1/cancellations', {
        method: 'POST',
        body: { version: 3, lines: [{ lineId: 'l1', quantity: 2 }], reason: 'Damaged' },
      }),
    );
  });

  it('offers only what has not shipped, and refuses more or nothing before sending', async () => {
    const [tee, socks] = confirmed().lines;
    api.mockResolvedValue(confirmed({ lines: [{ ...tee, shippedQuantity: 1, reservedQuantity: 2 }, { ...socks, shippedQuantity: 2, reservedQuantity: 0 }] }));
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel items' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel items on order 10001' });
    expect(within(dialog).queryByLabelText('Units of SOCKS to cancel')).toBeNull();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel 0 units' }));
    expect(within(dialog).getByText('Choose at least one unit to cancel.')).toBeTruthy();
    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to cancel'), { target: { value: '3' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /^Cancel \d+ units$/ }));
    expect(within(dialog).getByText('Only 2 left.')).toBeTruthy();

    // All that is left, after part shipped: the order will count as shipped.
    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to cancel'), { target: { value: '2' } });
    expect(within(dialog).getByText(/so the order will be marked as shipped/)).toBeTruthy();
    expect(api).toHaveBeenCalledTimes(1);
  });

  it('warns that cancelling everything cancels the order', async () => {
    api.mockResolvedValue(confirmed());
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel items' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel items on order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Units of TSHIRT-M to cancel'), { target: { value: '3' } });
    fireEvent.change(within(dialog).getByLabelText('Units of SOCKS to cancel'), { target: { value: '2' } });

    expect(within(dialog).getByText(/so the order will be cancelled/)).toBeTruthy();
  });

  it('explains a hold that has to be released first', async () => {
    api.mockResolvedValueOnce(confirmed()).mockRejectedValueOnce(conflict('release_first', 'status')).mockResolvedValue(confirmed());
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel items' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel items on order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Units of SOCKS to cancel'), { target: { value: '1' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel 1 units' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('Release it before');
  });
});

describe('the history of edits and cancels', () => {
  it('describes what an edit and a partial cancel changed, in Swedish too', async () => {
    const events = [
      ...orderFixture().events,
      {
        id: 'e2',
        type: 'edited',
        actor: { id: 'u1', name: 'Olle' },
        before: { lines: [{ position: 1, sku: 'TSHIRT-M', quantity: 3 }, { position: 2, sku: 'SOCKS', quantity: 2 }], total: 69650 },
        after: { lines: [{ position: 1, sku: 'TSHIRT-M', quantity: 5 }, { position: 3, sku: 'CAP', quantity: 1 }], total: 1, shippingAddress: {} },
        occurredAt: '2026-09-26T09:00:00+00:00',
      },
      {
        id: 'e3',
        type: 'lines_cancelled',
        actor: { id: 'u1', name: 'Olle' },
        before: { lines: [{ position: 1, sku: 'TSHIRT-M', cancelledQuantity: 0 }] },
        after: { lines: [{ position: 1, sku: 'TSHIRT-M', cancelled: 2, cancelledQuantity: 2 }], reason: 'Damaged' },
        occurredAt: '2026-09-26T10:00:00+00:00',
      },
    ];
    api.mockResolvedValue(confirmed({ events }));
    renderAt('/orders/o1');

    expect(await screen.findByText('Order edited: TSHIRT-M 3 → 5, SOCKS removed, CAP added (1), shipping address')).toBeTruthy();
    expect(screen.getByText('Items cancelled: 2 × TSHIRT-M · Reason: Damaged')).toBeTruthy();
  });

  it('shows the cancelled units per line', async () => {
    const [tee, socks] = confirmed().lines;
    api.mockResolvedValue(confirmed({ lines: [{ ...tee, cancelledQuantity: 1, reservedQuantity: 2 }, socks] }));
    renderAt('/orders/o1', { locale: 'sv' });

    const lines = await screen.findByRole('table', { name: 'Rader' });
    const headers = within(lines).getAllByRole('columnheader').map((cell) => cell.textContent);
    const cancelled = headers.indexOf('Avbokat');
    expect(within(lines).getAllByRole('row').slice(1, 3).map((row) => row.querySelectorAll('td')[cancelled].textContent)).toEqual(['1', '0']);
  });
});
