import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { renderApp } from '../features/orders/testing.jsx';
import { AppErrorBoundary } from './errors.jsx';
import { createTabRouter } from './router.jsx';

const broken = vi.hoisted(() => ({ page: false, router: false }));

// The pages ask the API for their data and wait; the shell does not need it.
vi.mock('../api/client.js', () => ({ api: vi.fn(() => new Promise(() => {})), ApiError: class extends Error {} }));

// A page with a bug in it, until the test fixes it.
vi.mock('../features/customers/CustomersPage.jsx', () => ({
  CustomersPage: function CustomersPage() {
    if (broken.page) throw new Error('Cannot read properties of undefined (reading "name")');
    return <h1>Customers, working</h1>;
  },
}));

// A tab whose router cannot even be made, until the test fixes it.
vi.mock('./router.jsx', async (importOriginal) => {
  const actual = await importOriginal();
  return {
    ...actual,
    createTabRouter: vi.fn((href) => {
      if (broken.router && href.startsWith('/customers')) throw new URIError('URI malformed');
      return actual.createTabRouter(href);
    }),
  };
});

const tabs = () => within(screen.getAllByRole('tablist', { name: 'Open pages' })[0]).getAllByRole('tab');
const selected = () => tabs().find((tab) => tab.getAttribute('aria-selected') === 'true')?.textContent;
const menu = () => screen.getByRole('navigation', { name: 'Main navigation' });
const errorPanel = () => screen.queryByRole('alert');

beforeEach(() => {
  broken.page = false;
  broken.router = false;
  createTabRouter.mockClear();
  // React and the router report every error they catch; the tests expect them.
  vi.spyOn(console, 'error').mockImplementation(() => {});
  vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => vi.restoreAllMocks());

/** The Orders tab still does what it did: here, its "New order" link opens the form in the same tab. */
async function ordersTabStillWorks() {
  fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
  fireEvent.click(await screen.findByRole('link', { name: 'New order' }));
  expect(await screen.findByRole('heading', { name: 'New order' })).toBeTruthy();
  expect(selected()).toBe('New order');
  expect(window.location.pathname).toBe('/orders/new');
}

describe('a page that fails to render', () => {
  it('shows an error in its own tab, leaves the other tabs working, and renders again when reloaded', async () => {
    broken.page = true;
    renderApp('/orders');
    expect(await screen.findByRole('heading', { name: 'Orders' })).toBeTruthy();

    fireEvent.click(within(menu()).getByRole('link', { name: 'Customers' }));

    const panel = await screen.findByRole('alert');
    expect(within(panel).getByRole('heading', { name: 'This page could not be shown' })).toBeTruthy();
    expect(within(panel).getByText('Something went wrong on this page. The other tabs are not affected.')).toBeTruthy();
    expect(within(panel).getByText('Details')).toBeTruthy();
    // The shell carries on: the menu and every tab are still there.
    expect(within(menu()).getByRole('link', { name: 'Orders' })).toBeTruthy();
    expect(tabs().map((tab) => tab.textContent)).toEqual(['Orders', 'Customers']);

    await ordersTabStillWorks();

    // Back in the broken tab, the error is still there, and only there.
    fireEvent.click(screen.getByRole('tab', { name: 'Customers' }));
    expect(errorPanel()).toBeTruthy();

    // The router caught it (RouteError): reloading renders the page again in the same router.
    const routers = createTabRouter.mock.calls.length;
    broken.page = false;
    fireEvent.click(within(errorPanel()).getByRole('button', { name: 'Reload the page' }));
    expect(await screen.findByRole('heading', { name: 'Customers, working' })).toBeTruthy();
    expect(errorPanel()).toBeNull();
    expect(createTabRouter.mock.calls.length).toBe(routers);
  });

  it('is in Swedish when the UI is', async () => {
    broken.page = true;
    renderApp('/customers', { locale: 'sv' });

    const panel = await screen.findByRole('alert');
    expect(within(panel).getByRole('heading', { name: 'Sidan kunde inte visas' })).toBeTruthy();
    expect(within(panel).getByRole('button', { name: 'Ladda om sidan' })).toBeTruthy();
  });
});

describe('a tab whose router fails', () => {
  it('shows the error in that tab alone, and reloading makes the tab afresh at its address', async () => {
    broken.router = true;
    renderApp('/orders');
    expect(await screen.findByRole('heading', { name: 'Orders' })).toBeTruthy();

    fireEvent.click(within(menu()).getByRole('link', { name: 'Customers' }));

    const panel = await screen.findByRole('alert');
    expect(within(panel).getByRole('heading', { name: 'This page could not be shown' })).toBeTruthy();
    expect(selected()).toBe('Customers');

    await ordersTabStillWorks();

    fireEvent.click(screen.getByRole('tab', { name: 'Customers' }));
    broken.router = false;
    fireEvent.click(within(errorPanel()).getByRole('button', { name: 'Reload the page' }));

    expect(await screen.findByRole('heading', { name: 'Customers, working' })).toBeTruthy();
    await waitFor(() => expect(createTabRouter).toHaveBeenLastCalledWith('/customers'));
  });
});

describe('the last resort', () => {
  function Broken() {
    throw new Error('The shell itself failed');
  }

  it('says so in the language chosen before, without a catalog, and offers to reload', () => {
    window.localStorage.setItem('kanso.locale', 'sv');
    const reload = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, reload });

    render(
      <AppErrorBoundary>
        <Broken />
      </AppErrorBoundary>,
    );

    const panel = screen.getByRole('alert');
    expect(within(panel).getByRole('heading', { name: 'Något gick fel' })).toBeTruthy();
    expect(within(panel).getByText('The shell itself failed')).toBeTruthy();
    fireEvent.click(within(panel).getByRole('button', { name: 'Ladda om' }));
    expect(reload).toHaveBeenCalled();
  });
});
