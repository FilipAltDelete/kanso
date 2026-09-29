import { focusManager } from '@tanstack/react-query';
import { act, fireEvent, screen, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { LIVE_REFRESH_MS } from '../../lib/liveQuery.js';
import { channelsFixture, orderFixture, renderAt } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const conflict = (code) => Object.assign(new Error('Conflict'), { status: 409, violations: [{ path: 'version', message: 'x', code }] });

/** Editable, as the server has it: `server.order` is what a GET answers with now. */
const server = { order: null };
const confirmed = (overrides = {}) => orderFixture({ status: 'confirmed', version: 3, canEdit: true, availableTransitions: ['allocate'], ...overrides });

const reads = (path) => api.mock.calls.filter(([url, options]) => url.split('?')[0] === path && !options?.method).length;
const tick = () => act(() => vi.advanceTimersByTimeAsync(LIVE_REFRESH_MS));

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
  vi.useFakeTimers({ shouldAdvanceTime: true });
  server.order = confirmed();
  api.mockReset();
  api.mockImplementation(async (url, options) => {
    const path = url.split('?')[0];
    if (path === '/api/orders/o1/edits') throw conflict('stale_version');
    if (path === '/api/orders/o1') return server.order;
    if (path === '/api/orders') return { member: [server.order], totalItems: 1 };
    if (path === '/api/channels') return channelsFixture;
    if (path === '/api/order-tags') return { member: [] };
    throw new Error(`Unexpected ${options?.method ?? 'GET'} ${url}`);
  });
});

afterEach(() => {
  vi.useRealTimers();
  focusManager.setFocused(undefined);
});

describe('the order page, kept up to date', () => {
  it('shows what changed elsewhere within 30 seconds, and when the window is focused again', async () => {
    renderAt('/orders/o1');
    expect(await screen.findByText('Confirmed')).toBeTruthy();

    server.order = confirmed({ status: 'allocated', version: 4 });
    await tick();
    expect(await screen.findByText('Allocated')).toBeTruthy();

    server.order = confirmed({ status: 'picking', version: 5 });
    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });
    expect(await screen.findByText('Picking')).toBeTruthy();
  });

  it('holds still while the edit dialog is open, and a save that is stale by then is still refused and shows the order as it is', async () => {
    renderAt('/orders/o1');
    fireEvent.click(await screen.findByRole('button', { name: 'Edit order' }));
    const dialog = screen.getByRole('dialog', { name: 'Edit order 10001' });
    fireEvent.change(within(dialog).getByLabelText('Customer name'), { target: { value: 'Anna Berg' } });
    const before = reads('/api/orders/o1');

    // Someone else changes the order; minutes pass with the dialog open.
    server.order = confirmed({ version: 4, customer: { id: null, name: 'Anna Andersson-Berg', email: 'anna@example.com' } });
    await tick();
    await tick();
    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });
    await act(() => vi.advanceTimersByTimeAsync(10));
    expect(reads('/api/orders/o1')).toBe(before);

    // So the save still carries the version the dialog opened on, and is refused.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    expect((await within(dialog).findByRole('alert')).textContent).toContain('Someone changed this order');
    expect(api).toHaveBeenCalledWith('/api/orders/o1/edits', expect.objectContaining({ body: expect.objectContaining({ version: 3 }) }));
    // The conflict fetched the order as it is now, dialog or not.
    expect(reads('/api/orders/o1')).toBe(before + 1);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(await screen.findByText('Anna Andersson-Berg')).toBeTruthy();
  });
});

describe('the order list, kept up to date', () => {
  it('asks again every 30 seconds and when the window is focused again', async () => {
    renderAt('/orders');
    expect(await screen.findByRole('link', { name: '10001' })).toBeTruthy();
    expect(reads('/api/orders')).toBe(1);

    await tick();
    expect(reads('/api/orders')).toBe(2);

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });
    await act(() => vi.advanceTimersByTimeAsync(10));
    expect(reads('/api/orders')).toBe(3);
  });
});
