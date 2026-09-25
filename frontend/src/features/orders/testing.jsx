import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { createMemoryHistory, createRouter, RouterProvider } from '@tanstack/react-router';
import { router as appRouter } from '../../app/router.jsx';
import { I18nProvider } from '../../lib/i18n.jsx';
import { AuthContext } from '../auth/AuthProvider.jsx';

export const operator = { id: 'u1', email: 'olle@example.com', name: 'Olle', roles: ['ROLE_OPERATOR'] };
export const viewer = { id: 'u2', email: 'vera@example.com', name: 'Vera', roles: ['ROLE_VIEWER'] };

/** The app's routes at `url`, signed in as `user`, with the API module mocked by the caller. */
export function renderAt(url, { user = operator, locale = 'en' } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createRouter({
    routeTree: appRouter.routeTree,
    history: createMemoryHistory({ initialEntries: [url] }),
    scrollRestoration: false,
  });

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

export function orderFixture(overrides = {}) {
  return {
    '@id': '/api/orders/o1',
    id: 'o1',
    number: '10001',
    status: 'pending',
    channel: { code: 'manual', name: 'Manual' },
    currency: 'SEK',
    paymentStatus: 'unpaid',
    tags: [],
    total: 69650,
    customer: { id: null, name: 'Anna Andersson', email: 'anna@example.com' },
    lineCount: 2,
    placedAt: '2026-09-26T08:00:00+00:00',
    updatedAt: '2026-09-26T08:00:00+00:00',
    version: 1,
    createdAt: '2026-09-26T08:00:00+00:00',
    shippingAddress: { line1: 'Storgatan 1', postalCode: '111 22', city: 'Stockholm', countryCode: 'SE' },
    lines: [
      { id: 'l1', position: 1, sku: 'TSHIRT-M', name: 'T-shirt, M', quantity: 3, unitPrice: 19950, lineTotal: 59850 },
      { id: 'l2', position: 2, sku: 'SOCKS', name: 'Socks', quantity: 2, unitPrice: 4900, lineTotal: 9800 },
    ],
    events: [
      { id: 'e1', type: 'created', actor: { id: 'u1', name: 'Olle' }, after: { status: 'pending' }, occurredAt: '2026-09-26T08:00:00+00:00' },
    ],
    availableTransitions: ['confirm', 'cancel', 'hold'],
    ...overrides,
  };
}

export const locationsFixture = {
  member: [
    { id: 'loc1', code: 'WH1', name: 'Main', addressLine1: null, addressLine2: null, postalCode: null, city: null, countryCode: null, version: 1 },
    { id: 'loc2', code: 'ST1', name: 'Store', addressLine1: null, addressLine2: null, postalCode: null, city: null, countryCode: null, version: 1 },
  ],
  totalItems: 2,
};

export const channelsFixture = { member: [{ code: 'manual', name: 'Manual', type: 'manual', currency: 'SEK' }] };
