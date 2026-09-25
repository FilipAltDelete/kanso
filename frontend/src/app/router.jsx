import { createRootRoute, createRoute, createRouter, lazyRouteComponent, Link } from '@tanstack/react-router';
import { CustomerDetailPage } from '../features/customers/CustomerDetailPage.jsx';
import { EditCustomerPage, NewCustomerPage } from '../features/customers/CustomerFormPages.jsx';
import { CustomersPage } from '../features/customers/CustomersPage.jsx';
import { DashboardPage } from '../features/dashboard/DashboardPage.jsx';
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

// Orders, inventory and products get their routes in Phase 1 (ROADMAP.md).
export const router = createRouter({ routeTree: rootRoute.addChildren([dashboardRoute, ...customerRoutes, settingsRoute, ...devRoutes]) });
