import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { orderFixture, renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

function documentFixture(overrides = {}) {
  return {
    id: 'd1',
    type: 'pick_list',
    orderId: 'o1',
    orderNumber: '10001',
    orderVersion: 1,
    locale: 'sv',
    status: 'queued',
    filename: 'pick-list-10001.pdf',
    downloadUrl: null,
    byteSize: null,
    requestedBy: { id: 'u1', name: 'Olle' },
    createdAt: '2026-09-26T08:00:00+00:00',
    completedAt: null,
    ...overrides,
  };
}

/** The API as the page sees it: the order, a document request, and the document's states in turn. */
function answer(documentStates) {
  const states = [...documentStates];
  api.mockImplementation(async (path, options = {}) => {
    if (path === '/api/orders/o1') return orderFixture();
    if (path === '/api/orders/o1/documents' && options.method === 'POST') return documentFixture();
    if (path === '/api/documents/d1') return states.length > 1 ? states.shift() : states[0];
    throw new Error(`Unexpected ${options.method ?? 'GET'} ${path}`);
  });
}

describe('printing from the order page', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('asks for a pick list in the UI language, waits, then offers the PDF', async () => {
    const url = 'http://localhost:19010/kanso-test/documents/d1.pdf?X-Amz-Signature=abc';
    answer([documentFixture({ status: 'running' }), documentFixture({ status: 'done', downloadUrl: url })]);
    renderAt('/orders/o1', { locale: 'sv' });

    const print = await screen.findByRole('region', { name: 'Skriv ut' });
    fireEvent.click(within(print).getByRole('button', { name: 'Plocklista' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/orders/o1/documents', { method: 'POST', body: { type: 'pick_list', locale: 'sv' } }));
    expect(await within(print).findByText('Förbereds…')).toBeTruthy();

    const link = await within(print).findByRole('link', { name: 'Öppna plocklistan (PDF)' }, { timeout: 4000 });
    expect(link.getAttribute('href')).toBe(url);
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toContain('noopener');
    expect(within(print).getByRole('button', { name: 'Följesedel' })).toBeTruthy();
  }, 10_000);

  it('says so when a document could not be made, and can try again', async () => {
    answer([documentFixture({ status: 'failed' })]);
    renderAt('/orders/o1');

    fireEvent.click(await screen.findByRole('button', { name: 'Pick list' }));

    expect(await screen.findByText('Could not be created.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Try the pick list again' }));
    await waitFor(() => expect(api.mock.calls.filter(([path, options]) => path === '/api/orders/o1/documents' && options?.method === 'POST')).toHaveLength(2));
  });

  it('is there for viewers too: printing changes nothing', async () => {
    answer([documentFixture()]);
    renderAt('/orders/o1', { user: viewer });

    expect(await screen.findByRole('button', { name: 'Packing slip' })).toBeTruthy();
  });
});
