import { createRootRoute, createRoute, createRouter, Link } from '@tanstack/react-router';
import { DashboardPage } from '../features/dashboard/DashboardPage.jsx';
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

// Orders, inventory, products and customers get their routes in Phase 1 (ROADMAP.md).
export const router = createRouter({ routeTree: rootRoute.addChildren([dashboardRoute]) });
