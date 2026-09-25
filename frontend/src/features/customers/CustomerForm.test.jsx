import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { toFormState, toPayload } from './CustomerForm.jsx';
import { anna, mockApi, renderCustomers } from './testing.jsx';

describe('the customer form', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('sends blanks as null and keeps the ids of existing addresses', () => {
    const state = toFormState({ ...anna, phone: null });
    state.addresses.push({ key: 'new-1', type: 'billing', isDefault: false, countryCode: 'NO', name: '', company: ' ACME ', line1: 'Gate 1', line2: '', postalCode: '0150', city: 'Oslo', region: '', phone: '' });

    const payload = toPayload(state);

    expect(payload.phone).toBeNull();
    expect(payload.addresses[0]).toMatchObject({ id: anna.addresses[0].id, line1: 'Storgatan 1', line2: null });
    expect(payload.addresses[1]).toEqual({
      type: 'billing',
      isDefault: false,
      countryCode: 'NO',
      name: null,
      company: 'ACME',
      line1: 'Gate 1',
      line2: null,
      postalCode: '0150',
      city: 'Oslo',
      region: null,
      phone: null,
    });
  });

  it('creates a customer and opens it', async () => {
    const calls = mockApi({
      'POST /api/customers': { status: 201, body: anna },
      [`GET /api/customers/${anna.id}`]: anna,
      [`GET /api/customers/${anna.id}/history`]: { member: [], totalItems: 0 },
    });
    const router = renderCustomers('/customers/new');

    fireEvent.change(await screen.findByLabelText(/^Name/), { target: { value: 'Anna Svensson' } });
    fireEvent.change(screen.getByLabelText(/^Email/), { target: { value: 'anna@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add shipping address' }));
    const address = screen.getByRole('group', { name: 'Shipping address 1' });
    fireEvent.change(within(address).getByLabelText(/^Address line 1/), { target: { value: 'Storgatan 1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(router.state.location.pathname).toBe(`/customers/${anna.id}`));
    const post = calls.find((call) => call.method === 'POST');
    expect(post.body).toMatchObject({ name: 'Anna Svensson', email: 'anna@example.com', phone: null });
    expect(post.body.addresses).toEqual([expect.objectContaining({ type: 'shipping', line1: 'Storgatan 1', countryCode: 'SE' })]);
  });

  it('shows what the API refused next to the field it names', async () => {
    mockApi({
      'POST /api/customers': {
        status: 422,
        body: {
          title: 'Unprocessable Entity',
          detail: 'The request is not valid.',
          violations: [
            { path: 'email', message: 'A customer with this email already exists.', code: 'duplicate' },
            { path: 'addresses[0].postalCode', message: 'Required.', code: 'required' },
            { path: 'addresses', message: 'A customer can have at most 50 addresses.', code: 'too_many' },
          ],
        },
      },
    });
    renderCustomers('/customers/new', { locale: 'sv' });

    fireEvent.click(await screen.findByRole('button', { name: 'Lägg till leveransadress' }));
    fireEvent.click(screen.getByRole('button', { name: 'Skapa kund' }));

    const summary = await screen.findByRole('alert');
    expect(within(summary).getByText('Några fält behöver åtgärdas.')).toBeTruthy();
    expect(within(summary).getByText('Det är för många adresser.')).toBeTruthy();

    const email = screen.getByLabelText(/^E-post/);
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(email.getAttribute('aria-describedby')).textContent).toBe('Det finns redan en kund med den här e-postadressen.');
    expect(within(screen.getByRole('group', { name: 'Leveransadress 1' })).getByText('Obligatoriskt.')).toBeTruthy();
  });

  it('keeps one default address per type', async () => {
    mockApi({});
    renderCustomers('/customers/new');

    fireEvent.click(await screen.findByRole('button', { name: 'Add shipping address' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add shipping address' }));
    const [first, second] = screen.getAllByRole('checkbox', { name: 'Default for its type' });

    fireEvent.click(first);
    fireEvent.click(second);

    expect(first.checked).toBe(false);
    expect(second.checked).toBe(true);
  });
});
