import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { api } from '../../api/client.js';
import { I18nProvider } from '../../lib/i18n.jsx';
import { BulkPrintDialog } from './BulkPrintDialog.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const batch = (overrides = {}) => ({
  id: 'd1',
  type: 'pick_list',
  orderId: null,
  orderNumber: null,
  shipmentId: null,
  orderVersion: null,
  orders: [
    { id: 'o1', number: '10001', version: 2 },
    { id: 'o2', number: '10002', version: 1 },
  ],
  locale: 'sv',
  status: 'queued',
  filename: 'pick-lists-20260925-1432.pdf',
  downloadUrl: null,
  byteSize: null,
  createdAt: '2026-09-25T14:32:00+00:00',
  completedAt: null,
  ...overrides,
});

function renderDialog({ type = 'pick_list', ids = ['o1', 'o2', 'o3'], locale = 'en' } = {}) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <I18nProvider locale={locale}>
        <BulkPrintDialog type={type} ids={ids} onClose={vi.fn()} />
      </I18nProvider>
    </QueryClientProvider>,
  );
}

describe('the bulk print dialog', () => {
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

  it('asks once for one PDF in the UI language, and offers it when done, with what was left out', async () => {
    api.mockImplementation(async (path) =>
      path === '/api/orders/bulk-documents'
        ? { document: batch(), skipped: [{ id: 'o3', number: '10003', code: 'on_hold', message: 'Order 10003 is on hold.' }] }
        : batch({ status: 'done', downloadUrl: 'http://store/d1.pdf', byteSize: 1234, completedAt: '2026-09-25T14:32:05+00:00' }),
    );

    renderDialog({ locale: 'sv' });

    const link = await screen.findByRole('link', { name: 'Öppna plocklistor för 2 ordrar (PDF)' });
    expect(link.getAttribute('href')).toBe('http://store/d1.pdf');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(screen.getByText('Utelämnade (1):')).toBeTruthy();
    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual(['10003: Den är pausad; återuppta den innan plockning.']);
    expect(api.mock.calls.filter(([path]) => path === '/api/orders/bulk-documents')).toEqual([
      ['/api/orders/bulk-documents', { method: 'POST', body: { type: 'pick_list', locale: 'sv', orderIds: ['o1', 'o2', 'o3'] } }],
    ]);
  });

  it('says so when none of the orders can be printed', async () => {
    api.mockResolvedValue({
      document: null,
      skipped: [
        { id: 'o1', number: '10001', code: 'cancelled', message: 'Order 10001 is cancelled.' },
        { id: 'gone', number: null, code: 'not_found', message: 'No order "gone".' },
      ],
    });

    renderDialog({ type: 'packing_slip', ids: ['o1', 'gone'] });

    expect(await screen.findByText('None of the selected orders can be printed:')).toBeTruthy();
    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual(['10001: It is cancelled.', 'An order that no longer exists: It no longer exists.']);
    expect(screen.queryByRole('link')).toBeNull();
    expect(api).toHaveBeenCalledTimes(1);
  });

  it('offers to try again when the worker gave up', async () => {
    api.mockImplementation(async (path) => (path === '/api/orders/bulk-documents' ? { document: batch(), skipped: [] } : batch({ status: 'failed' })));

    renderDialog();

    expect(await screen.findByRole('button', { name: 'Try again' })).toBeTruthy();
    expect(screen.getByText('Could not be created.')).toBeTruthy();
  });

  it('does not ask for more orders than fit in one PDF', async () => {
    renderDialog({ ids: Array.from({ length: 101 }, (_, i) => `o${i}`) });

    expect(screen.getByRole('alert').textContent).toBe('At most 100 orders fit in one PDF. Select fewer and print them in turns.');
    await waitFor(() => expect(api).not.toHaveBeenCalled());
  });
});
