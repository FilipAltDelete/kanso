import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { renderAt, viewer } from '../orders/testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

class ApiError extends Error {
  constructor(status, violations = []) {
    super('The request is not valid.');
    this.status = status;
    this.violations = violations;
  }
}

const preview = {
  dryRun: true,
  rows: 4,
  created: 2,
  updated: 1,
  unchanged: 0,
  failed: 1,
  errors: [{ row: 5, sku: 'bad sku', field: 'sku', code: 'format', message: 'A SKU is 1–64 characters with no spaces.' }],
  importRunId: null,
};

const pastRun = {
  id: 'run-1',
  type: 'products',
  filename: 'spring.csv',
  actorId: 'u1',
  actorName: 'Olle',
  counts: { rows: 3, created: 1, updated: 1, unchanged: 0, failed: 1 },
  errorCount: 1,
  startedAt: '2026-09-27T10:00:00+00:00',
};

/**
 * Import requests get `responses` in turn (the last one repeats; an Error is
 * thrown); the history gets `runs`, and one run by id gets it with its problems.
 */
function answer(responses, runs = []) {
  const queue = [...responses];
  api.mockImplementation((path) => {
    if (path.startsWith('/api/import-runs/')) return Promise.resolve({ ...pastRun, errors: preview.errors });
    if (path.startsWith('/api/import-runs')) return Promise.resolve({ member: runs, totalItems: runs.length });
    const response = queue.length > 1 ? queue.shift() : queue[0];

    return response instanceof Error ? Promise.reject(response) : Promise.resolve(response);
  });
}

const importCalls = () => api.mock.calls.filter(([path]) => path.startsWith('/api/product-imports'));

async function chooseFile(content = 'sku;name\nA;One\n', label = 'Choose file') {
  const file = new File([content], 'products.csv', { type: 'text/csv' });
  fireEvent.change(await screen.findByLabelText(label), { target: { files: [file] } });

  return file;
}

describe('the product import page', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('previews the file with a dry run, listing the rows that fail', async () => {
    answer([preview]);
    renderAt('/products/import');

    const file = await chooseFile();

    expect(await screen.findByText('Preview: nothing has been imported yet')).toBeTruthy();
    expect(importCalls()).toEqual([['/api/product-imports?dryRun=true&filename=products.csv', expect.objectContaining({ method: 'POST', body: file })]]);

    const problems = screen.getByRole('region', { name: 'Problems (1)' });
    expect(within(problems).getByText('bad sku')).toBeTruthy();
    expect(within(problems).getByText('A SKU is 1–64 characters with no spaces.')).toBeTruthy();
    expect(screen.getByText(/Rows with problems are skipped/)).toBeTruthy();
  });

  it('imports the same file for real and reports what it did', async () => {
    answer([preview, { ...preview, dryRun: false, importRunId: 'run-2' }]);
    renderAt('/products/import');

    const file = await chooseFile();
    fireEvent.click(await screen.findByRole('button', { name: 'Import 3 products' }));

    expect(await screen.findByText('Import finished')).toBeTruthy();
    expect(importCalls().at(-1)).toEqual(['/api/product-imports?dryRun=false&filename=products.csv', expect.objectContaining({ method: 'POST', body: file })]);
    expect(screen.getByText('Created')).toBeTruthy();
  });

  it('offers nothing to import when every row is already as in the file', async () => {
    answer([{ ...preview, created: 0, updated: 0, unchanged: 4, failed: 0, errors: [] }]);
    renderAt('/products/import');

    await chooseFile();

    expect((await screen.findByRole('button', { name: 'Nothing to import' })).disabled).toBe(true);
  });

  it('explains a file it cannot read, in the UI language', async () => {
    answer([new ApiError(422, [{ path: 'header.weight', code: 'unknown_column', message: 'Unknown column "weight".' }])]);
    renderAt('/products/import', { locale: 'sv' });

    await chooseFile('sku;name;weight\nA;One;1\n', 'Välj fil');

    expect(await screen.findByText('Okänd kolumn "weight". Kolumnerna finns i listan ovan.')).toBeTruthy();
  });

  it('does not send a file over the size limit', async () => {
    answer([preview]);
    renderAt('/products/import');

    await chooseFile('x'.repeat(1024 * 1024 + 1));

    expect(await screen.findByText('The file is larger than 1 MB. Split it into smaller files.')).toBeTruthy();
    expect(importCalls()).toEqual([]);
  });

  it('lists past imports, and a run’s problems open in a dialog', async () => {
    HTMLDialogElement.prototype.showModal ??= function showModal() {
      this.open = true;
    };
    answer([preview], [pastRun]);
    renderAt('/products/import');

    const history = await screen.findByRole('region', { name: 'Recent imports' });
    expect(await within(history).findByText('spring.csv')).toBeTruthy();
    expect(within(history).getByText('Olle')).toBeTruthy();
    expect(within(history).getByText('Rows 3 · Created 1 · Updated 1 · Unchanged 0')).toBeTruthy();

    fireEvent.click(within(history).getByRole('button', { name: 'Show 1' }));

    const dialog = await screen.findByRole('dialog', { name: 'Problems in spring.csv' });
    expect(await within(dialog).findByText('bad sku')).toBeTruthy();
    expect(api).toHaveBeenCalledWith('/api/import-runs/run-1', expect.anything());
  });

  it('lets a viewer read the page but not import', async () => {
    answer([preview]);
    renderAt('/products/import', { user: viewer });

    expect(await screen.findByText('You can view products but not import them.')).toBeTruthy();
    await waitFor(() => expect(screen.getByLabelText('Choose file').disabled).toBe(true));
  });
});
