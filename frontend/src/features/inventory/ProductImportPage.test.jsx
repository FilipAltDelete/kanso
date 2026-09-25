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
};

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
    api.mockResolvedValue(preview);
    renderAt('/products/import');

    const file = await chooseFile();

    expect(await screen.findByText('Preview: nothing has been imported yet')).toBeTruthy();
    expect(api).toHaveBeenCalledWith('/api/product-imports?dryRun=true', expect.objectContaining({ method: 'POST', body: file }));

    const problems = screen.getByRole('region', { name: 'Problems (1)' });
    expect(within(problems).getByText('bad sku')).toBeTruthy();
    expect(within(problems).getByText('A SKU is 1–64 characters with no spaces.')).toBeTruthy();
    expect(screen.getByText(/Rows with problems are skipped/)).toBeTruthy();
  });

  it('imports the same file for real and reports what it did', async () => {
    api.mockResolvedValueOnce(preview).mockResolvedValueOnce({ ...preview, dryRun: false });
    renderAt('/products/import');

    const file = await chooseFile();
    fireEvent.click(await screen.findByRole('button', { name: 'Import 3 products' }));

    expect(await screen.findByText('Import finished')).toBeTruthy();
    expect(api).toHaveBeenLastCalledWith('/api/product-imports?dryRun=false', expect.objectContaining({ method: 'POST', body: file }));
    expect(screen.getByText('Created')).toBeTruthy();
  });

  it('offers nothing to import when every row is already as in the file', async () => {
    api.mockResolvedValue({ ...preview, created: 0, updated: 0, unchanged: 4, failed: 0, errors: [] });
    renderAt('/products/import');

    await chooseFile();

    expect((await screen.findByRole('button', { name: 'Nothing to import' })).disabled).toBe(true);
  });

  it('explains a file it cannot read, in the UI language', async () => {
    api.mockRejectedValue(new ApiError(422, [{ path: 'header.weight', code: 'unknown_column', message: 'Unknown column "weight".' }]));
    renderAt('/products/import', { locale: 'sv' });

    await chooseFile('sku;name;weight\nA;One;1\n', 'Välj fil');

    expect(await screen.findByText('Okänd kolumn "weight". Kolumnerna finns i listan ovan.')).toBeTruthy();
  });

  it('does not send a file over the size limit', async () => {
    renderAt('/products/import');

    await chooseFile('x'.repeat(1024 * 1024 + 1));

    expect(await screen.findByText('The file is larger than 1 MB. Split it into smaller files.')).toBeTruthy();
    expect(api).not.toHaveBeenCalled();
  });

  it('lets a viewer read the page but not import', async () => {
    renderAt('/products/import', { user: viewer });

    expect(await screen.findByText('You can view products but not import them.')).toBeTruthy();
    await waitFor(() => expect(screen.getByLabelText('Choose file').disabled).toBe(true));
  });
});
