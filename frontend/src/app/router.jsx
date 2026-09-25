import { createRootRoute, createRoute, createRouter, lazyRouteComponent, Link } from '@tanstack/react-router';
import { CustomerDetailPage } from '../features/customers/CustomerDetailPage.jsx';
import { EditCustomerPage, NewCustomerPage } from '../features/customers/CustomerFormPages.jsx';
import { CustomersPage } from '../features/customers/CustomersPage.jsx';
import { DashboardPage } from '../features/dashboard/DashboardPage.jsx';
import { CreateOrderPage } from '../features/orders/CreateOrderPage.jsx';
import { OrderDetailPage } from '../features/orders/OrderDetailPage.jsx';
import { OrderListPage } from '../features/orders/OrderListPage.jsx';
import { LocationsPage } from '../features/inventory/LocationsPage.jsx';
import { ProductDetailPage } from '../features/inventory/ProductDetailPage.jsx';
import { ProductImportPage } from '../features/inventory/ProductImportPage.jsx';
import { ProductsPage } from '../features/inventory/ProductsPage.jsx';
import { SettingsPage } from '../features/settings/SettingsPage.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { Layout } from './Layout.jsx';

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

const rootRoute = createRootRoute({ component: Layout, notFoundComponent: NotFound });

const dashboardRoute = createRoute({ getParentRoute: () => rootRoute, path: '/', component: DashboardPage });

const settingsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/settings', component: SettingsPage });

const productsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products', component: ProductsPage });

// A literal segment outranks a parameter, so /products/import never reads as a product id.
const productImportRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products/import', component: ProductImportPage });

const productRoute = createRoute({ getParentRoute: () => rootRoute, path: '/products/$productId', component: ProductDetailPage });

const locationsRoute = createRoute({ getParentRoute: () => rootRoute, path: '/locations', component: LocationsPage });

const orderRoutes = [
  createRoute({ getParentRoute: () => rootRoute, path: '/orders', component: OrderListPage }),
  // A literal segment outranks a parameter, so /orders/new never reads as an order id.
  createRoute({ getParentRoute: () => rootRoute, path: '/orders/new', component: CreateOrderPage }),
  createRoute({ getParentRoute: () => rootRoute, path: '/orders/$orderId', component: OrderDetailPage }),
];

const customerRoutes = [
  createRoute({ getParentRoute: () => rootRoute, path: '/customers', component: CustomersPage }),
  createRoute({ getParentRoute: () => rootRoute, path: '/customers/new', component: NewCustomerPage }),
  createRoute({ getParentRoute: () => rootRoute, path: '/customers/$customerId', component: CustomerDetailPage }),
  createRoute({ getParentRoute: () => rootRoute, path: '/customers/$customerId/edit', component: EditCustomerPage }),
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

// Every Phase 1 module now has its routes (ROADMAP.md).
export const router = createRouter({ routeTree: rootRoute.addChildren([dashboardRoute, ...orderRoutes, ...customerRoutes, productsRoute, productImportRoute, productRoute, locationsRoute, settingsRoute, ...devRoutes]) });
