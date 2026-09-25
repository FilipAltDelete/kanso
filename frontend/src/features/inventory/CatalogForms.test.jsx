import { fireEvent, screen, waitFor } from '@testing-library/react';
import { api } from '../../api/client.js';
import { renderAt, viewer } from '../orders/testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

class ApiError extends Error {
  constructor(status, violations = []) {
    super(status === 409 ? 'Conflict' : 'The request is not valid.');
    this.status = status;
    this.violations = violations;
  }
}

const product = {
  id: 'p1',
  sku: 'TEE-1',
  name: 'Tee',
  barcode: '7350000000001',
  weightGrams: 180,
  version: 3,
  onHand: 0,
  reserved: 0,
  available: 0,
  createdAt: '2026-09-25T10:00:00+00:00',
  updatedAt: '2026-09-25T10:00:00+00:00',
};
const location = { id: 'l1', code: 'WH1', name: 'Main', addressLine1: null, addressLine2: null, postalCode: null, city: 'Hemsjö', countryCode: 'SE', version: 2 };
const page = (member) => ({ member, totalItems: member.length });

/** Answers GETs by path; writes go to `write`. */
function routeApi(write, reads = {}) {
  api.mockImplementation((path, options = {}) => {
    if ((options.method ?? 'GET') !== 'GET') return write(path, options);
    const match = Object.entries(reads).find(([prefix]) => path.startsWith(prefix));

    return Promise.resolve(match ? (typeof match[1] === 'function' ? match[1]() : match[1]) : page([]));
  });
}

const lastWrite = () => api.mock.calls.filter(([, options]) => options?.method && options.method !== 'GET').at(-1);

describe('catalog forms', () => {
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

  it('creates a product and opens it', async () => {
    const write = vi.fn(() => Promise.resolve(product));
    routeApi(write, { '/api/products/p1': product });
    const router = renderAt('/products');

    fireEvent.click(await screen.findByRole('button', { name: 'New product' }));
    fireEvent.change(screen.getByLabelText(/SKU/), { target: { value: ' TEE-1 ' } });
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Tee' } });
    fireEvent.change(screen.getByLabelText('Weight (grams)'), { target: { value: '180' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/products/p1'));
    expect(lastWrite()).toEqual(['/api/products', { method: 'POST', body: { sku: 'TEE-1', name: 'Tee', barcode: null, weightGrams: 180 } }]);
  });

  it('checks the form before sending it', async () => {
    const write = vi.fn();
    routeApi(write);
    renderAt('/products');

    fireEvent.click(await screen.findByRole('button', { name: 'New product' }));
    fireEvent.change(screen.getByLabelText(/SKU/), { target: { value: 'has space' } });
    fireEvent.change(screen.getByLabelText('Weight (grams)'), { target: { value: '1.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    expect(screen.getByText('A SKU is 1–64 characters with no spaces.')).toBeTruthy();
    expect(screen.getByText('Enter a name of at most 255 characters.')).toBeTruthy();
    expect(screen.getByText('Whole grams between 0 and 10,000,000.')).toBeTruthy();
    expect(write).not.toHaveBeenCalled();
  });

  it('shows a taken SKU at its field', async () => {
    routeApi(() => Promise.reject(new ApiError(422, [{ path: 'sku', code: 'taken', message: 'SKU "TEE-1" already exists.' }])));
    renderAt('/products');

    fireEvent.click(await screen.findByRole('button', { name: 'New product' }));
    fireEvent.change(screen.getByLabelText(/SKU/), { target: { value: 'TEE-1' } });
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Tee' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create product' }));

    expect(await screen.findByText('There is already a product with this SKU.')).toBeTruthy();
  });

  it('edits a product with the version it was based on, and never the SKU', async () => {
    const write = vi.fn(() => Promise.resolve({ ...product, name: 'Better tee', barcode: null, version: 4 }));
    routeApi(write, { '/api/products/p1': product, '/api/locations': page([]), '/api/inventory': page([]) });
    renderAt('/products/p1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    expect(screen.queryByLabelText(/SKU/)).toBeNull();
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Better tee' } });
    fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(write).toHaveBeenCalled());
    expect(lastWrite()[1]).toEqual({
      method: 'PATCH',
      body: { name: 'Better tee', barcode: null, weightGrams: 180, version: 3 },
      headers: { 'Content-Type': 'application/merge-patch+json' },
    });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('reloads the saved product after someone else changed it', async () => {
    let current = product;
    routeApi(
      () => {
        current = { ...product, name: 'Their tee', version: 4 };
        return Promise.reject(new ApiError(409));
      },
      { '/api/products/p1': () => current, '/api/locations': page([]), '/api/inventory': page([]) },
    );
    renderAt('/products/p1');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'My tee' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText(/Someone else changed this product/)).toBeTruthy();
    await waitFor(() => expect(screen.getByLabelText(/^Name/).value).toBe('Their tee'));
  });

  it('creates a location with the country in capitals', async () => {
    const write = vi.fn(() => Promise.resolve(location));
    routeApi(write);
    renderAt('/locations');

    fireEvent.click(await screen.findByRole('button', { name: 'New location' }));
    fireEvent.change(screen.getByRole('textbox', { name: /^Location/ }), { target: { value: 'WH1' } });
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Main' } });
    fireEvent.change(screen.getByLabelText('City'), { target: { value: 'Hemsjö' } });
    fireEvent.change(screen.getByLabelText('Country'), { target: { value: 'se' } });
    expect(screen.getByText('Sweden')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Create location' }));

    await waitFor(() => expect(write).toHaveBeenCalled());
    expect(lastWrite()[1].body).toEqual({ code: 'WH1', name: 'Main', addressLine1: null, addressLine2: null, postalCode: null, city: 'Hemsjö', countryCode: 'SE' });
  });

  it('edits a location from its row', async () => {
    const write = vi.fn(() => Promise.resolve({ ...location, name: 'Central', version: 3 }));
    routeApi(write, { '/api/locations': page([location]) });
    renderAt('/locations');

    fireEvent.click(await screen.findByRole('button', { name: 'Edit WH1' }));
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Central' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(write).toHaveBeenCalled());
    expect(lastWrite()[0]).toBe('/api/locations/l1');
    expect(lastWrite()[1].body).toMatchObject({ name: 'Central', city: 'Hemsjö', countryCode: 'SE', version: 2 });
  });

  it('shows a viewer no way to change the catalog', async () => {
    routeApi(vi.fn(), { '/api/locations': page([location]) });
    renderAt('/locations', { user: viewer });

    expect(await screen.findByText('WH1')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'New location' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit WH1' })).toBeNull();
  });
});
