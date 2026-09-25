import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryHistory, createRootRoute, createRoute, createRouter, Outlet, RouterProvider } from '@tanstack/react-router';
import { AuthContext } from '../auth/AuthProvider.jsx';
import { I18nProvider } from '../../lib/i18n.jsx';
import { CustomerDetailPage } from './CustomerDetailPage.jsx';
import { EditCustomerPage, NewCustomerPage } from './CustomerFormPages.jsx';
import { CustomersPage } from './CustomersPage.jsx';

export const anna = {
  id: '0192a1b2-0000-7000-8000-000000000001',
  email: 'anna@example.com',
  name: 'Anna Svensson',
  phone: '+46 70 123 45 67',
  addresses: [
    {
      id: '0192a1b2-0000-7000-8000-0000000000a1',
      type: 'shipping',
      isDefault: true,
      name: null,
      company: null,
      line1: 'Storgatan 1',
      line2: null,
      postalCode: '111 22',
      city: 'Stockholm',
      region: null,
      countryCode: 'SE',
      phone: null,
    },
  ],
  createdAt: '2026-09-20T08:00:00+00:00',
  updatedAt: '2026-09-21T09:30:00+00:00',
};

/**
 * A fetch that answers from a table of `METHOD path` → a body, or
 * `{ status, body }` for anything but a 200, recording every call.
 */
export function mockApi(routes) {
  const calls = [];
  const fetch = vi.fn(async (url, init = {}) => {
    const method = init.method ?? 'GET';
    const path = String(url).split('?')[0];
    calls.push({ method, url: String(url), body: init.body ? JSON.parse(init.body) : undefined });
    const answer = routes[`${method} ${path}`];
    if (!answer) return { ok: false, status: 404, json: async () => ({ title: 'Not Found', detail: 'Not found.' }) };
    const { status, body } = 'status' in answer && 'body' in answer ? answer : { status: 200, body: answer };

    return { ok: status < 400, status, json: async () => body };
  });
  vi.stubGlobal('fetch', fetch);

  return calls;
}

/** The customer pages under a memory router, signed in with the given roles. */
export function renderCustomers(path, { roles = ['ROLE_OPERATOR'], locale = 'en' } = {}) {
  const root = createRootRoute({ component: Outlet });
  const routes = [
    createRoute({ getParentRoute: () => root, path: '/customers', component: CustomersPage }),
    createRoute({ getParentRoute: () => root, path: '/customers/new', component: NewCustomerPage }),
    createRoute({ getParentRoute: () => root, path: '/customers/$customerId', component: CustomerDetailPage }),
    createRoute({ getParentRoute: () => root, path: '/customers/$customerId/edit', component: EditCustomerPage }),
  ];
  const router = createRouter({ routeTree: root.addChildren(routes), history: createMemoryHistory({ initialEntries: [path] }) });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const user = { id: 'u1', email: 'ops@example.com', name: 'Ops', roles };

  render(
    <I18nProvider locale={locale}>
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={{ status: 'authenticated', user, login: vi.fn(), logout: vi.fn() }}>
          <RouterProvider router={router} />
        </AuthContext.Provider>
      </QueryClientProvider>
    </I18nProvider>,
  );

  return router;
}
