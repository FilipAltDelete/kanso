import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { channelsFixture, orderFixture, renderAt } from './testing.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const type = (label, value, container = document.body) => fireEvent.change(within(container).getByLabelText(label), { target: { value } });

function fillMinimalOrder() {
  type('Kundens namn (obligatoriskt) *', 'Anna Andersson');
  const shipping = screen.getByRole('group', { name: 'Leveransadress' });
  type('Adress (obligatoriskt) *', 'Storgatan 1', shipping);
  type('Postnummer (obligatoriskt) *', '111 22', shipping);
  type('Ort (obligatoriskt) *', 'Stockholm', shipping);
  const line = screen.getByRole('listitem', { name: 'Rad 1' });
  type('Artikelnr (obligatoriskt) *', 'TSHIRT-M', line);
  type('Benämning (obligatoriskt) *', 'T-shirt, M', line);
  type('Antal (obligatoriskt) *', '3', line);
  type('Styckpris (obligatoriskt) *', '199,50', line);
}

describe('creating an order', () => {
  beforeEach(() => {
    api.mockReset();
    api.mockImplementation(async (path, options) => {
      if (path === '/api/channels') return channelsFixture;
      if (path === '/api/orders' && options?.method === 'POST') return orderFixture();
      if (path === '/api/orders/o1') return orderFixture();
      throw new Error(`Unexpected ${path}`);
    });
  });

  it('sends prices as integer minor units and opens the new order', async () => {
    const router = renderAt('/orders/new', { locale: 'sv' });
    await screen.findByRole('heading', { name: 'Ny order' });

    fillMinimalOrder();
    expect(within(screen.getByRole('listitem', { name: 'Rad 1' })).getByText(/598,50\s*kr/)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Skapa order' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/orders/o1'));
    const [, { body }] = api.mock.calls.find(([path, options]) => path === '/api/orders' && options?.method === 'POST');
    expect(body).toEqual({
      channel: 'manual',
      customer: { name: 'Anna Andersson' },
      shippingAddress: { line1: 'Storgatan 1', postalCode: '111 22', city: 'Stockholm', countryCode: 'SE' },
      lines: [{ sku: 'TSHIRT-M', name: 'T-shirt, M', quantity: 3, unitPrice: 19950 }],
    });
  });

  it('refuses a price it cannot read, before sending anything', async () => {
    renderAt('/orders/new', { locale: 'sv' });
    await screen.findByRole('heading', { name: 'Ny order' });
    fillMinimalOrder();
    type('Styckpris (obligatoriskt) *', '19,999', screen.getByRole('listitem', { name: 'Rad 1' }));

    fireEvent.click(screen.getByRole('button', { name: 'Skapa order' }));

    const price = within(screen.getByRole('listitem', { name: 'Rad 1' })).getByLabelText('Styckpris (obligatoriskt) *');
    expect(price.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByText('Ange ett pris, till exempel 199,50.')).toBeTruthy();
    expect(api.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false);
  });

  it('shows the server’s violations at their fields, in the viewer’s language', async () => {
    api.mockImplementation(async (path, options) => {
      if (path === '/api/channels') return channelsFixture;
      if (options?.method === 'POST') {
        throw Object.assign(new Error('The request is not valid.'), {
          status: 422,
          violations: [
            { path: 'shippingAddress.countryCode', message: 'This value must be a two-letter ISO 3166 country code, such as SE.', code: 'country' },
            { path: 'lines[0].sku', message: 'This value is longer than 64 characters.', code: 'too_long' },
          ],
        });
      }
      throw new Error(`Unexpected ${path}`);
    });
    renderAt('/orders/new', { locale: 'sv' });
    await screen.findByRole('heading', { name: 'Ny order' });
    fillMinimalOrder();

    fireEvent.click(screen.getByRole('button', { name: 'Skapa order' }));

    expect((await screen.findByRole('alert')).textContent).toMatch(/Ordern skapades inte/);
    const country = within(screen.getByRole('group', { name: 'Leveransadress' })).getByLabelText('Landskod (t.ex. SE) (obligatoriskt) *');
    expect(country.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByText('Ange en landskod med två bokstäver, till exempel SE.')).toBeTruthy();
    expect(screen.getByText('För långt.')).toBeTruthy();
  });

  it('adds and removes lines and totals them', async () => {
    renderAt('/orders/new');
    await screen.findByRole('heading', { name: 'New order' });
    type('Unit price (required) *', '10', screen.getByRole('listitem', { name: 'Line 1' }));

    fireEvent.click(screen.getByRole('button', { name: 'Add line' }));
    const second = screen.getByRole('listitem', { name: 'Line 2' });
    type('Quantity (required) *', '2', second);
    type('Unit price (required) *', '2.50', second);

    const total = () => screen.getByText('Total:').closest('p').textContent.replace(/\s+/g, ' ');
    expect(total()).toBe('Total: SEK 15.00');
    fireEvent.click(screen.getByRole('button', { name: 'Remove line 2' }));
    expect(total()).toBe('Total: SEK 10.00');
    expect(screen.getByRole('button', { name: 'Remove line 1' }).disabled).toBe(true);
  });
});
