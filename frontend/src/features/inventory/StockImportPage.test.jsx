import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { renderAt, viewer } from '../orders/testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const preview = {
  dryRun: true,
  rows: 4,
  changed: 2,
  unchanged: 1,
  failed: 1,
  errors: [{ row: 5, sku: 'NOPE', location: 'WH1', field: 'sku', code: 'not_found', message: 'No product has this SKU.' }],
  importRunId: null,
};

const pastRun = {
  id: 'run-1',
  type: 'stock',
  filename: 'stock.csv',
  actorId: 'u1',
  actorName: 'Olle',
  counts: { rows: 4, changed: 2, unchanged: 1, failed: 1 },
  errorCount: 1,
  startedAt: '2026-09-27T10:00:00+00:00',
};

/** Import requests get `responses` in turn (the last one repeats); the history gets `runs`. */
function answer(responses, runs = []) {
  const queue = [...responses];
  api.mockImplementation((path) => {
    if (path.startsWith('/api/import-runs/')) return Promise.resolve({ ...pastRun, errors: preview.errors });
    if (path.startsWith('/api/import-runs')) return Promise.resolve({ member: runs, totalItems: runs.length });

    return Promise.resolve(queue.length > 1 ? queue.shift() : queue[0]);
  });
}

const importCalls = () => api.mock.calls.filter(([path]) => path.startsWith('/api/stock-imports'));

async function chooseFile(label = 'Choose file') {
  const file = new File(['sku;location;quantity\nTEE-1;WH1;12\n'], 'stock.csv', { type: 'text/csv' });
  fireEvent.change(await screen.findByLabelText(label), { target: { files: [file] } });

  return file;
}

describe('the stock import page', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('previews the file, listing each failing row with its SKU and location', async () => {
    answer([preview]);
    renderAt('/stock/import');

    const file = await chooseFile();

    expect(await screen.findByText('Preview: nothing has been imported yet')).toBeTruthy();
    expect(importCalls()).toEqual([['/api/stock-imports?dryRun=true&filename=stock.csv', expect.objectContaining({ method: 'POST', body: file })]]);
    expect(screen.getByText('To set')).toBeTruthy();

    const problems = screen.getByRole('region', { name: 'Problems (1)' });
    expect(within(problems).getByText('NOPE')).toBeTruthy();
    expect(within(problems).getByText('WH1')).toBeTruthy();
    expect(within(problems).getByText('No product has this SKU.')).toBeTruthy();
  });

  it('imports the same file for real and reports what it set', async () => {
    answer([preview, { ...preview, dryRun: false, importRunId: 'run-2' }]);
    renderAt('/stock/import');

    const file = await chooseFile();
    fireEvent.click(await screen.findByRole('button', { name: 'Set 2 stock levels' }));

    expect(await screen.findByText('Import finished')).toBeTruthy();
    expect(importCalls().at(-1)).toEqual(['/api/stock-imports?dryRun=false&filename=stock.csv', expect.objectContaining({ method: 'POST', body: file })]);
  });

  it('offers nothing to import when every quantity is already on hand', async () => {
    answer([{ ...preview, changed: 0, unchanged: 4, failed: 0, errors: [] }]);
    renderAt('/stock/import');

    await chooseFile();

    expect((await screen.findByRole('button', { name: 'Nothing to import' })).disabled).toBe(true);
  });

  it('explains a row problem in the UI language', async () => {
    answer([preview]);
    renderAt('/stock/import', { locale: 'sv' });

    await chooseFile('Välj fil');

    expect(await screen.findByText('Ingen produkt har den här artikeln.')).toBeTruthy();
  });

  it('lists past stock imports', async () => {
    answer([preview], [pastRun]);
    renderAt('/stock/import');

    const history = await screen.findByRole('region', { name: 'Recent imports' });
    expect(await within(history).findByText('stock.csv')).toBeTruthy();
    expect(within(history).getByText('Rows 4 · Set 2 · Unchanged 1')).toBeTruthy();
    expect(api).toHaveBeenCalledWith(expect.stringMatching(/^\/api\/import-runs\?type=stock&/), expect.anything());
  });

  it('lets a viewer read the page but not import', async () => {
    answer([preview]);
    renderAt('/stock/import', { user: viewer });

    expect(await screen.findByText('You can view stock but not import it.')).toBeTruthy();
    await waitFor(() => expect(screen.getByLabelText('Choose file').disabled).toBe(true));
  });
});
