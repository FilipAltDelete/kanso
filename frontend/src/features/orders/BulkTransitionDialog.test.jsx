import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { api } from '../../api/client.js';
import { I18nProvider } from '../../lib/i18n.jsx';
import { BulkTransitionDialog } from './BulkTransitionDialog.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function renderDialog({ ids = ['o1', 'o2', 'o3'], rows = [{ id: 'o1', version: 4 }, { id: 'o2', version: 1 }] } = {}) {
  const onDone = vi.fn();
  const onClose = vi.fn();
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
      <I18nProvider locale="en">
        <BulkTransitionDialog ids={ids} rows={rows} onClose={onClose} onDone={onDone} />
      </I18nProvider>
    </QueryClientProvider>,
  );

  return { onDone, onClose };
}

describe('the bulk status dialog', () => {
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

  it('sends the versions it knows, and closes when every order moved', async () => {
    api.mockResolvedValue({ transition: 'confirm', moved: [{ id: 'o1', number: '10001', status: 'confirmed', version: 5 }], failed: [] });
    const { onDone, onClose } = renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: 'confirm' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply to 3 orders' }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(api).toHaveBeenCalledWith('/api/orders/bulk-transitions', {
      method: 'POST',
      body: { transition: 'confirm', orders: [{ id: 'o1', version: 4 }, { id: 'o2', version: 1 }, { id: 'o3' }] },
    });
    expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ transition: 'confirm' }));
  });

  it('stays open to say which orders could not move and why', async () => {
    api.mockResolvedValue({
      transition: 'confirm',
      moved: [{ id: 'o1', number: '10001', status: 'confirmed', version: 5 }],
      failed: [
        { id: 'o2', number: '10002', code: 'insufficient_stock', message: 'Not enough stock.' },
        { id: 'o3', number: null, code: 'not_found', message: 'No order "o3".' },
      ],
    });
    const { onClose } = renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: 'confirm' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply to 3 orders' }));

    expect(await screen.findByText('Confirm: 1 orders changed, 2 could not be:')).toBeTruthy();
    expect(screen.getByText((_, element) => element.tagName === 'LI' && element.textContent === '10002: Not enough stock to confirm it.')).toBeTruthy();
    expect(screen.getByText((_, element) => element.tagName === 'LI' && element.textContent === 'An order that no longer exists: It no longer exists.')).toBeTruthy();
    expect(onClose).not.toHaveBeenCalled();
  });

  it('asks before cancelling', async () => {
    api.mockResolvedValue({ transition: 'cancel', moved: [], failed: [] });
    renderDialog();

    fireEvent.change(screen.getByLabelText('Change'), { target: { value: 'cancel' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply to 3 orders' }));

    expect(screen.getByText('Tick the box to cancel the orders.')).toBeTruthy();
    expect(api).not.toHaveBeenCalled();

    fireEvent.click(screen.getByLabelText('Cancel 3 orders. Cancelled orders cannot be reopened.'));
    fireEvent.click(screen.getByRole('button', { name: 'Apply to 3 orders' }));

    await waitFor(() => expect(api).toHaveBeenCalled());
  });
});
