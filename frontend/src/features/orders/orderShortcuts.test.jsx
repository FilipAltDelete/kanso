import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { channelsFixture, orderFixture, renderApp, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const second = orderFixture({ '@id': '/api/orders/o2', id: 'o2', number: '10002' });

function answer({ order = orderFixture() } = {}) {
  api.mockImplementation(async (path, options = {}) => {
    if (path.startsWith('/api/channels')) return channelsFixture;
    if (path.startsWith('/api/order-tags')) return { member: [{ name: 'VIP', orders: 3 }] };
    if (path.startsWith('/api/orders?')) return { member: [orderFixture(), second], totalItems: 2 };
    if (path === '/api/orders/o1' && !options.method) return order;
    if (path === '/api/orders/o1/transitions') return orderFixture({ status: 'confirmed', version: 2, availableTransitions: ['allocate', 'cancel', 'hold'] });
    if (path === '/api/orders/o1/documents') return { id: 'd1', type: 'pick_list', orderId: 'o1', orderNumber: '10001', orderVersion: 1, locale: 'en', status: 'queued', filename: 'x.pdf', downloadUrl: null, byteSize: null, requestedBy: { id: 'u1', name: 'Olle' }, createdAt: '2026-09-26T08:00:00+00:00', completedAt: null };
    if (path.startsWith('/api/documents/')) return new Promise(() => {});
    // Pages the shortcuts lead to may ask for anything; they are not what is tested here.
    return new Promise(() => {});
  });
}

const press = (key, target = document.body, init = {}) => fireEvent.keyDown(target, { key, ...init });

describe('keyboard shortcuts on the order pages', () => {
  beforeAll(() => {
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
    localStorage.clear();
  });

  describe('the order list', () => {
    it('starts a new order with n, but not while typing a search', async () => {
      answer();
      const router = renderAt('/orders');
      await screen.findByRole('link', { name: '10001' });

      press('n', screen.getByLabelText('Search'));
      expect(router.state.location.pathname).toBe('/orders');

      press('n');
      // The route change can take over a second when the whole suite runs at once.
      await waitFor(() => expect(router.state.location.pathname).toBe('/orders/new'), { timeout: 3000 });
    });

    it('opens "Add tag" for the selected orders with t, and says to select first', async () => {
      answer();
      renderAt('/orders');
      await screen.findByRole('link', { name: '10001' });

      press('t');
      expect(await screen.findByText('Select orders first, then press t to tag them.')).toBeTruthy();
      expect(screen.queryByRole('dialog')).toBeNull();

      const checkbox = screen.getByRole('checkbox', { name: /10002/ });
      fireEvent.click(checkbox);
      press('t', checkbox);
      expect(screen.getByRole('dialog', { name: 'Add a tag to the selected orders (1)' })).toBeTruthy();
    });

    it('goes places with g and a letter, as tabs', async () => {
      answer();
      renderApp('/orders');
      await screen.findByRole('link', { name: '10001' });

      press('g');
      press('p');
      await waitFor(() => expect(window.location.pathname).toBe('/products'));
      press('g');
      press('o');
      await waitFor(() => expect(window.location.pathname).toBe('/orders'));
      expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Orders', 'Products']);
    });

    it('jumps to the search with /', async () => {
      answer();
      renderApp('/orders');
      await screen.findByRole('link', { name: '10001' });

      press('/');
      expect(document.activeElement).toBe(screen.getByLabelText('Search'));
    });

    it('offers a viewer no order-list shortcuts, and lists what there is on ?', async () => {
      answer();
      const router = renderAt('/orders', { user: viewer });
      await screen.findByRole('link', { name: '10001' });

      press('n');
      expect(router.state.location.pathname).toBe('/orders');

      press('?', document.body, { shiftKey: true });
      const dialog = screen.getByRole('dialog', { name: 'Keyboard shortcuts' });
      expect(within(dialog).queryByRole('region', { name: 'Order list' })).toBeNull();
      expect(within(dialog).getByText('Go to orders')).toBeTruthy();
    });

    it('opens the list of shortcuts from the menu', async () => {
      answer();
      renderApp('/orders');
      await screen.findByRole('link', { name: '10001' });

      fireEvent.click(screen.getByRole('button', { name: 'Keyboard shortcuts' }));
      const dialog = screen.getByRole('dialog', { name: 'Keyboard shortcuts' });
      const list = within(dialog).getByRole('region', { name: 'Order list' });
      expect(within(list).getByText('New order')).toBeTruthy();
      expect(within(list).getByText('Add a tag to the selected orders')).toBeTruthy();
    });
  });

  describe('an order', () => {
    it('takes the highlighted next step with a', async () => {
      answer();
      renderAt('/orders/o1');
      await screen.findByRole('button', { name: 'Confirm' });

      press('a');
      await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/transitions', { method: 'POST', body: { transition: 'confirm', version: 1 } }));
    });

    it('never cancels with a: an order whose first action asks first has no next step', async () => {
      answer({ order: orderFixture({ status: 'on_hold', heldFrom: 'pending', availableTransitions: ['cancel', 'release'] }) });
      renderAt('/orders/o1');
      await screen.findByRole('button', { name: 'Release hold' });

      press('a');
      expect(screen.queryByRole('group', { name: 'Cancel order' })).toBeNull();
      expect(api).not.toHaveBeenCalledWith('/api/orders/o1/transitions', expect.anything());
    });

    it('focuses the note with n and the tag field with t, where typing is safe', async () => {
      answer();
      renderAt('/orders/o1');
      await screen.findByRole('button', { name: 'Confirm' });

      press('n');
      const note = screen.getByLabelText('Add a note');
      expect(document.activeElement).toBe(note);
      // Letters typed into the note are the note's.
      press('a', note);
      expect(api).not.toHaveBeenCalledWith('/api/orders/o1/transitions', expect.anything());

      press('t');
      expect(document.activeElement).toBe(screen.getByLabelText('New tag'));
    });

    it('prints the pick list with p and goes back to the list with u', async () => {
      answer();
      const router = renderAt('/orders/o1');
      await screen.findByRole('button', { name: 'Confirm' });

      press('p');
      await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/documents', { method: 'POST', body: { type: 'pick_list', locale: 'en' } }));

      press('u');
      await waitFor(() => expect(router.state.location.pathname).toBe('/orders'));
    });

    it('lists the order shortcuts on ?, and gives a viewer only print and back', async () => {
      answer();
      renderAt('/orders/o1', { user: viewer });
      await screen.findByRole('heading', { name: 'Order 10001' });

      press('n');
      expect(screen.queryByLabelText('Add a note')).toBeNull();

      press('?', document.body, { shiftKey: true });
      const section = within(screen.getByRole('dialog')).getByRole('region', { name: 'Order' });
      expect(within(section).getAllByRole('term').map((term) => term.textContent)).toEqual(['Print the pick list', 'Back to the order list']);
    });
  });
});
