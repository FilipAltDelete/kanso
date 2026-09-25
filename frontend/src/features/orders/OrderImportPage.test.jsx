import { fireEvent, screen, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { renderAt, viewer } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const preview = {
  dryRun: true,
  rows: 5,
  orders: 3,
  created: 1,
  existing: 1,
  failed: 1,
  errors: [
    { row: 4, reference: 'WEB-1002', field: 'sku', code: 'unknown_sku', message: 'No product with SKU "NOPE".' },
    { row: 5, reference: 'WEB-1002', field: 'customerName', code: 'inconsistent', message: 'Row 4 of this order says "Anna".' },
  ],
};

async function chooseFile(label = 'Choose file') {
  const file = new File(['orderReference;customerName\n'], 'orders.csv', { type: 'text/csv' });
  fireEvent.change(await screen.findByLabelText(label), { target: { files: [file] } });

  return file;
}

describe('the order import page', () => {
  beforeEach(() => {
    api.mockReset();
  });

  it('is opened from the order list', async () => {
    api.mockResolvedValue({ member: [], totalItems: 0 });
    const router = renderAt('/orders');

    fireEvent.click(await screen.findByRole('link', { name: 'Import CSV' }));

    expect(await screen.findByRole('heading', { name: 'Import orders' })).toBeTruthy();
    expect(router.state.location.pathname).toBe('/orders/import');
  });

  it('previews the file, listing the rows of every order that fails', async () => {
    api.mockResolvedValue(preview);
    renderAt('/orders/import');

    const file = await chooseFile();

    expect(await screen.findByText('Preview: nothing has been imported yet')).toBeTruthy();
    expect(api).toHaveBeenCalledWith('/api/order-imports?dryRun=true', expect.objectContaining({ method: 'POST', body: file }));
    expect(screen.getByText('Already imported')).toBeTruthy();

    const problems = screen.getByRole('region', { name: 'Problems (2)' });
    expect(within(problems).getByText('No product with this SKU.')).toBeTruthy();
    expect(within(problems).getByText(/differs from an earlier row of the same order/)).toBeTruthy();
    expect(within(problems).getAllByText('WEB-1002')).toHaveLength(2);
  });

  it('documents the payment status, tags and note columns and explains their problems', async () => {
    api.mockResolvedValue({
      ...preview,
      errors: [
        { row: 2, reference: 'WEB-1001', field: 'paymentStatus', code: 'unknown_payment_status', message: 'Unknown payment status "free".' },
        { row: 3, reference: 'WEB-1002', field: 'tags', code: 'tag', message: '"a,b" is not a valid tag.' },
        { row: 4, reference: 'WEB-1003', field: 'tags', code: 'too_many_tags', message: 'At most 20 tags.' },
        { row: 5, reference: 'WEB-1004', field: 'note', code: 'too_long', message: 'Too long.' },
      ],
    });
    renderAt('/orders/import');

    expect(await screen.findByText(/tags \(several separated by \|/)).toBeTruthy();
    await chooseFile();

    const problems = await screen.findByRole('region', { name: 'Problems (4)' });
    expect(within(problems).getByText(/Unknown payment status\. Use unpaid/)).toBeTruthy();
    expect(within(problems).getByText('A tag is 1 to 64 characters without commas. Separate tags with |.')).toBeTruthy();
    expect(within(problems).getByText('An order can have at most 20 tags.')).toBeTruthy();
    expect(within(problems).getByText('The note is longer than 2000 characters.')).toBeTruthy();
  });

  it('imports the orders the preview would create', async () => {
    api.mockResolvedValueOnce(preview).mockResolvedValueOnce({ ...preview, dryRun: false });
    renderAt('/orders/import');

    const file = await chooseFile();
    fireEvent.click(await screen.findByRole('button', { name: 'Import 1 orders' }));

    expect(await screen.findByText('Import finished')).toBeTruthy();
    expect(api).toHaveBeenLastCalledWith('/api/order-imports?dryRun=false', expect.objectContaining({ body: file }));
    expect(screen.getByRole('link', { name: 'Go to orders' })).toBeTruthy();
  });

  it('has nothing to import when every order is already there', async () => {
    api.mockResolvedValue({ ...preview, created: 0, existing: 3, failed: 0, errors: [] });
    renderAt('/orders/import', { locale: 'sv' });

    await chooseFile('Välj fil');

    expect((await screen.findByRole('button', { name: 'Inget att importera' })).disabled).toBe(true);
    expect(screen.getByText('Redan importerade')).toBeTruthy();
  });

  it('shows a viewer no way to import', async () => {
    api.mockResolvedValue({ member: [], totalItems: 0 });
    renderAt('/orders', { user: viewer });

    expect(await screen.findByRole('heading', { name: 'Orders' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Import CSV' })).toBeNull();
  });
});
