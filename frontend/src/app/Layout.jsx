import { Link, Outlet } from '@tanstack/react-router';
import { Boxes, LayoutDashboard, LogOut, Package, Settings, ShoppingCart, Users } from 'lucide-react';
import { Badge, Button } from '../components/ui/primitives.jsx';
import { useAuth } from '../features/auth/AuthProvider.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { LanguageSelect } from './LanguageSelect.jsx';

/** Modules on the roadmap, shown so the shape of the app is visible; they become links as they land. */
const upcoming = [
  { key: 'nav.orders', icon: ShoppingCart },
  { key: 'nav.inventory', icon: Boxes },
  { key: 'nav.products', icon: Package },
];

export function Layout() {
  const { user, logout } = useAuth();
  const { t } = useI18n();

  return (
    <div className="flex min-h-full flex-col md:flex-row">
      <aside className="border-b border-slate-200 bg-white md:w-60 md:shrink-0 md:border-b-0 md:border-r">
        <div className="px-4 py-4 text-base font-semibold">{t('app.name')}</div>
        <nav aria-label={t('nav.main')} className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:pb-4">
          <Link
            to="/"
            className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
            activeProps={{ className: 'bg-slate-100 font-medium text-slate-900' }}
            activeOptions={{ exact: true }}
          >
            <LayoutDashboard className="size-4" aria-hidden="true" />
            {t('nav.dashboard')}
          </Link>
          {upcoming.map(({ key, icon: Icon }) => (
            <span
              key={key}
              aria-disabled="true"
              className="flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-400"
            >
              <Icon className="size-4" aria-hidden="true" />
              {t(key)}
              <Badge className="ml-auto">{t('nav.comingSoon')}</Badge>
            </span>
          ))}
          <Link
            to="/customers"
            className="flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
            activeProps={{ className: 'bg-slate-100 font-medium text-slate-900' }}
          >
            <Users className="size-4" aria-hidden="true" />
            {t('nav.customers')}
          </Link>
          <Link
            to="/settings"
            className="flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 md:mt-4"
            activeProps={{ className: 'bg-slate-100 font-medium text-slate-900' }}
          >
            <Settings className="size-4" aria-hidden="true" />
            {t('nav.settings')}
          </Link>
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-end gap-3 border-b border-slate-200 bg-white px-4 py-2">
          <LanguageSelect />
          <span className="truncate text-sm text-slate-600">{user?.name ?? user?.email}</span>
          <Button variant="ghost" size="sm" onClick={logout}>
            <LogOut className="size-4" aria-hidden="true" />
            {t('auth.signOut')}
          </Button>
        </header>
        <main className="flex-1 p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
