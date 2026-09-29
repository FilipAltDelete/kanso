import { createMemoryHistory, createRootRoute, createRoute, createRouter, lazyRouteComponent, Link, Outlet } from '@tanstack/react-router';
import { Spinner } from '../components/ui/primitives.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { RouteError } from './errors.jsx';

function NotFound() {
  const { t } = useI18n();

  return (
    <div className="p-6">
      <h1 className="text-lg font-semibold">{t('notFound.title')}</h1>
      <Link to="/" className="mt-2 inline-block text-sm text-slate-600 underline">
        {t('notFound.back')}
      </Link>
    </div>
  );
}

/**
 * A router for one workspace tab (app/workspace), as in Pimsen.
 *
 * Every tab has its own, over an in-memory history, so two tabs can show the
 * same list with different filters and a dialog open in one is not open in
 * the other: each page keeps its state in its URL, and each tab has its own
 * URL. The browser's address bar follows the tab in front; the workspace keeps
 * it in step. The shell (menu, tabs) is outside the routers.
 */
export function createTabRouter(href) {
  return createRouter({
    routeTree: buildRouteTree(),
    history: createMemoryHistory({ initialEntries: [href] }),
    defaultPreload: 'intent',
    // Shown only when a page's code takes more than a second to arrive.
    defaultPendingComponent: Spinner,
    // What gets past RouteError goes on to the tab's own boundary, which
    // speaks the person's language; the router's fallback speaks English.
    disableGlobalCatchBoundary: true,
  });
}

/**
 * Each page is a chunk of its own, downloaded when a tab first shows it — or
 * when a link to it is hovered (`defaultPreload`). The main bundle holds the
 * shell alone.
 */
const pages = {
  DashboardPage: () => import('../features/dashboard/DashboardPage.jsx'),
  SettingsPage: () => import('../features/settings/SettingsPage.jsx'),
  ProductsPage: () => import('../features/inventory/ProductsPage.jsx'),
  ProductImportPage: () => import('../features/inventory/ProductImportPage.jsx'),
  ProductDetailPage: () => import('../features/inventory/ProductDetailPage.jsx'),
  LocationsPage: () => import('../features/inventory/LocationsPage.jsx'),
  StockImportPage: () => import('../features/inventory/StockImportPage.jsx'),
  OrderListPage: () => import('../features/orders/OrderListPage.jsx'),
  CreateOrderPage: () => import('../features/orders/CreateOrderPage.jsx'),
  OrderImportPage: () => import('../features/orders/OrderImportPage.jsx'),
  OrderDetailPage: () => import('../features/orders/OrderDetailPage.jsx'),
  CustomersPage: () => import('../features/customers/CustomersPage.jsx'),
  NewCustomerPage: () => import('../features/customers/CustomerFormPages.jsx'),
  CustomerDetailPage: () => import('../features/customers/CustomerDetailPage.jsx'),
  EditCustomerPage: () => import('../features/customers/CustomerFormPages.jsx'),
};

const page = (name) => lazyRouteComponent(pages[name], name);

/**
 * The pages, built per router: routes are not meant to be shared between
 * router instances. A page that throws while rendering, or whose code cannot
 * be downloaded, shows RouteError in its place; the tab and the rest of the
 * app carry on.
 */
export function buildRouteTree() {
  const rootRoute = createRootRoute({ component: Outlet, notFoundComponent: NotFound, errorComponent: RouteError });

  const dashboardRoute = createRoute({ getParentRoute: () => rootRoute, path: '/', component: page('DashboardPage') });

  const settingsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/settings', component: page('SettingsPage') });

  const productsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products', component: page('ProductsPage') });

  // A literal segment outranks a parameter, so /products/import never reads as a product id.
  const productImportRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products/import', component: page('ProductImportPage') });

  const productRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products/$productId', component: page('ProductDetailPage') });

  const locationsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/locations', component: page('LocationsPage') });

  const stockImportRoute = createRoute({ getParentRoute: () => rootRoute, path: '/stock/import', component: page('StockImportPage') });

  const orderRoutes = [
    createRoute({ getParentRoute: () => rootRoute, path: '/orders', component: page('OrderListPage') }),
    // A literal segment outranks a parameter, so /orders/new and /orders/import never read as an order id.
    createRoute({ getParentRoute: () => rootRoute, path: '/orders/new', component: page('CreateOrderPage') }),
    createRoute({ getParentRoute: () => rootRoute, path: '/orders/import', component: page('OrderImportPage') }),
    createRoute({ getParentRoute: () => rootRoute, path: '/orders/$orderId', component: page('OrderDetailPage') }),
  ];

  const customerRoutes = [
    createRoute({ getParentRoute: () => rootRoute, path: '/customers', component: page('CustomersPage') }),
    createRoute({ getParentRoute: () => rootRoute, path: '/customers/new', component: page('NewCustomerPage') }),
    createRoute({ getParentRoute: () => rootRoute, path: '/customers/$customerId', component: page('CustomerDetailPage') }),
    createRoute({ getParentRoute: () => rootRoute, path: '/customers/$customerId/edit', component: page('EditCustomerPage') }),
  ];

  // A dev build's playground for the table component; production builds drop it and its fake data.
  const devRoutes = import.meta.env.DEV
    ? [
        createRoute({
          getParentRoute: () => rootRoute,
          path: '/dev/table',
          component: lazyRouteComponent(() => import('../components/ui/table/DataTableDemo.jsx'), 'DataTableDemo'),
        }),
      ]
    : [];

  return rootRoute.addChildren([dashboardRoute, ...orderRoutes, ...customerRoutes, productsRoute, productImportRoute, productRoute, locationsRoute, stockImportRoute, settingsRoute, ...devRoutes]);
}
