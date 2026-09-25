import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { I18nProvider } from '../../lib/i18n.jsx';
import { AdjustStockDialog } from './AdjustStockDialog.jsx';

const location = { id: 'loc-1', code: 'WH1', name: 'Main' };
const movement = {
  id: 'm1',
  type: 'adjustment',
  reason: 'received',
  note: null,
  locationId: 'loc-1',
  locationCode: 'WH1',
  locationName: 'Main',
  onHandChange: 5,
  onHandBefore: 10,
  onHandAfter: 15,
  reservedBefore: 2,
  reservedAfter: 2,
  actorName: 'Ops',
  occurredAt: '2026-09-25T10:00:00+00:00',
};

function respond(status, body) {
  return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function renderDialog({ stock = { onHand: 10, reserved: 2, version: 4 }, onClose = vi.fn() } = {}) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
      <I18nProvider locale="en">
        <AdjustStockDialog productId="p-1" sku="TEE-1" location={location} stock={stock} onClose={onClose} />
      </I18nProvider>
    </QueryClientProvider>,
  );

  return { onClose };
}

function sentBody() {
  return JSON.parse(fetch.mock.calls.at(-1)[1].body);
}

describe('the adjust-stock dialog', () => {
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

  beforeEach(() => vi.stubGlobal('fetch', vi.fn()));
  afterEach(() => vi.unstubAllGlobals());

  it('shows the current stock and what it will be after the change', () => {
    renderDialog();

    expect(screen.getByRole('dialog', { name: 'Adjust stock' })).toBeTruthy();
    expect(screen.getByText('TEE-1 at WH1 · Main')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: '5' } });

    expect(screen.getByText('On hand after: 15')).toBeTruthy();
  });

  it('sends the change with the version it was based on', async () => {
    fetch.mockReturnValueOnce(respond(201, movement));
    const { onClose } = renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'received' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save adjustment' }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(fetch.mock.calls[0][0]).toBe('/api/stock-adjustments');
    expect(sentBody()).toEqual({ productId: 'p-1', locationId: 'loc-1', delta: 5, reason: 'received', note: null, expectedVersion: 4 });
  });

  it('sets a counted quantity, with "stock count" chosen for you', async () => {
    fetch.mockReturnValueOnce(respond(201, movement));
    renderDialog();

    fireEvent.click(screen.getByLabelText('Set counted quantity'));
    fireEvent.change(screen.getByLabelText('Counted quantity'), { target: { value: '8' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save adjustment' }));

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    expect(sentBody()).toMatchObject({ onHand: 8, reason: 'count', expectedVersion: 4 });
    expect(sentBody()).not.toHaveProperty('delta');
  });

  it('refuses what the server would refuse, before sending it', () => {
    renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: '-9' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'other' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save adjustment' }));

    expect(screen.getByText('On hand cannot go below the 2 reserved for orders.')).toBeTruthy();
    expect(screen.getByText('Say what happened.')).toBeTruthy();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('stays open and says so when someone else changed the stock first', async () => {
    fetch.mockReturnValueOnce(respond(409, { title: 'Conflict', status: 409, detail: 'The stock changed since you looked at it.' }));
    const { onClose } = renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'received' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save adjustment' }));

    expect(await screen.findByRole('alert')).toHaveProperty('textContent', expect.stringContaining('Someone changed this stock'));
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Change')).toHaveProperty('value', '5');
  });
});
