import { useState } from 'react';
import {
  LayoutDashboard,
  LogOut,
  MapPin,
  Package,
  PanelLeftClose,
  PanelLeftOpen,
  Settings,
  ShoppingCart,
  Upload,
  Users,
  Wrench,
} from 'lucide-react';
import { Button } from '../components/ui/primitives.jsx';
import { useAuth } from '../features/auth/AuthProvider.jsx';
import { useI18n } from '../lib/i18n.jsx';
import { useShortcuts } from '../lib/ShortcutsProvider.jsx';
import { cn } from '../lib/utils.js';
import { LanguageSelect } from './LanguageSelect.jsx';
import { NavFolder, NavLink } from './nav/NavLink.jsx';
import { Workspace } from './workspace/Workspace.jsx';
import { useWorkspace } from './workspace/WorkspaceProvider.jsx';
import { pathOf } from './workspace/workspace.js';
import { activeHref, loadCollapsed, loadFolders, saveCollapsed, saveFolders } from './nav/navState.js';

/** The pages of each folder; the icons are what the collapsed rail shows. */
const FOLDERS = [
  {
    id: 'inventory',
    labelKey: 'nav.inventory',
    items: [
      { href: '/products', labelKey: 'nav.products', icon: Package },
      { href: '/locations', labelKey: 'nav.locations', icon: MapPin },
    ],
  },
];

const ADMIN_TOOLS = [{ href: '/products/import', labelKey: 'nav.importProducts', icon: Upload }];

const HREFS = ['/', '/orders', '/customers', '/settings', ...FOLDERS.flatMap((folder) => folder.items.map((item) => item.href)), ...ADMIN_TOOLS.map((item) => item.href)];

/**
 * The signed-in shell: the menu on the left, as in Pimsen, and the page. The
 * menu folds to a rail of icons; both that and its open folders are
 * remembered in this browser.
 */
export function Layout() {
  const { user, logout } = useAuth();
  const { t } = useI18n();
  const { current } = useWorkspace();
  const active = current ? activeHref(pathOf(current.href), HREFS) : null;
  const [collapsed, setCollapsed] = useState(loadCollapsed);
  const [folders, setFolders] = useState(loadFolders);
  // Hiding a link is a courtesy; the API's voters are what refuse.
  const isAdmin = user?.roles?.includes('ROLE_ADMIN') ?? false;
  useGlobalShortcuts();

  function toggleCollapsed() {
    setCollapsed((current) => {
      saveCollapsed(!current);
      return !current;
    });
  }

  const isOpen = (id, fallback = true) => folders[id] ?? fallback;

  function toggleFolder(id, fallback = true) {
    setFolders((current) => {
      const next = { ...current, [id]: !(current[id] ?? fallback) };
      saveFolders(next);
      return next;
    });
  }

  const name = user?.name ?? user?.email ?? '';
  const brand = t('app.name');

  return (
    <div className="flex h-dvh overflow-hidden bg-slate-50">
      <nav
        aria-label={t('nav.main')}
        className={cn('flex shrink-0 flex-col border-r border-slate-200 bg-white transition-[width] duration-150', collapsed ? 'w-14' : 'w-60')}
      >
        <div className={cn('flex items-start gap-2 border-b border-slate-200 py-4', collapsed ? 'flex-col items-center px-2' : 'px-4')}>
          {collapsed ? (
            <p className="flex size-8 items-center justify-center rounded-md bg-slate-100 font-semibold text-slate-900" title={`${brand} — ${name}`}>
              {brand.slice(0, 1)}
            </p>
          ) : (
            <div className="min-w-0 flex-1">
              <p className="truncate font-semibold text-slate-900">{brand}</p>
              <p className="truncate text-xs text-slate-500" title={user?.email}>
                {name}
              </p>
            </div>
          )}
          <button
            type="button"
            onClick={toggleCollapsed}
            aria-expanded={!collapsed}
            aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
            title={collapsed ? t('nav.expand') : t('nav.collapse')}
            className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
          >
            {collapsed ? <PanelLeftOpen aria-hidden="true" className="size-4" /> : <PanelLeftClose aria-hidden="true" className="size-4" />}
          </button>
        </div>

        <div className={cn('flex-1 overflow-y-auto overflow-x-hidden', collapsed ? 'space-y-1 px-2 py-2' : 'p-2')}>
          <NavLink href="/" icon={LayoutDashboard} active={active === '/'} collapsed={collapsed}>
            {t('nav.dashboard')}
          </NavLink>

          {collapsed ? (
            <hr className="my-2 border-slate-200" />
          ) : (
            <p className="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('nav.operations')}</p>
          )}

          <NavLink href="/orders" icon={ShoppingCart} active={active === '/orders'} collapsed={collapsed}>
            {t('nav.orders')}
          </NavLink>

          {FOLDERS.map((folder) => {
            const links = folder.items.map((item) => (
              <NavLink
                key={item.href}
                href={item.href}
                icon={item.icon}
                nested
                active={active === item.href}
                collapsed={collapsed}
              >
                {t(item.labelKey)}
              </NavLink>
            ));

            // On the rail a folder is nothing but its pages' icons.
            if (collapsed) return links;

            return (
              <NavFolder
                key={folder.id}
                label={t(folder.labelKey)}
                open={isOpen(folder.id)}
                onToggle={() => toggleFolder(folder.id)}
                containsActive={folder.items.some((item) => item.href === active)}
              >
                {links}
              </NavFolder>
            );
          })}

          <NavLink href="/customers" icon={Users} active={active === '/customers'} collapsed={collapsed}>
            {t('nav.customers')}
          </NavLink>
        </div>

        <div className={cn('border-t border-slate-200', collapsed ? 'space-y-1 px-2 py-2' : 'p-2')}>
          {isAdmin ? (
            collapsed ? (
              ADMIN_TOOLS.map((item) => (
                <NavLink key={item.href} href={item.href} icon={item.icon} active={active === item.href} collapsed>
                  {t(item.labelKey)}
                </NavLink>
              ))
            ) : (
              <NavFolder
                icon={Wrench}
                label={t('nav.adminTools')}
                open={isOpen('admin', false)}
                onToggle={() => toggleFolder('admin', false)}
                containsActive={ADMIN_TOOLS.some((item) => item.href === active)}
              >
                {ADMIN_TOOLS.map((item) => (
                  <NavLink key={item.href} href={item.href} icon={item.icon} nested active={active === item.href}>
                    {t(item.labelKey)}
                  </NavLink>
                ))}
              </NavFolder>
            )
          ) : null}
          <NavLink href="/settings" icon={Settings} active={active === '/settings'} collapsed={collapsed}>
            {t('nav.settings')}
          </NavLink>
          {collapsed ? null : (
            <div className="px-3 py-1">
              <LanguageSelect />
            </div>
          )}
          <Button
            variant="ghost"
            size="sm"
            onClick={logout}
            title={collapsed ? t('auth.signOut') : undefined}
            aria-label={collapsed ? t('auth.signOut') : undefined}
            className={cn('w-full', collapsed ? 'justify-center px-0' : 'justify-start')}
          >
            <LogOut aria-hidden="true" className="size-4" />
            {collapsed ? null : t('auth.signOut')}
          </Button>
        </div>
      </nav>

      <main className="flex min-w-0 flex-1 flex-col">
        <Workspace />
      </main>
    </div>
  );
}

/** Shortcuts that work on every page: going places (a tab, brought forward or opened), and jumping to the page's search. */
function useGlobalShortcuts() {
  const { open } = useWorkspace();
  const go = (to) => () => open(to);

  useShortcuts({
    goDashboard: go('/'),
    goOrders: go('/orders'),
    goProducts: go('/products'),
    goCustomers: go('/customers'),
    goLocations: go('/locations'),
    search: () => {
      // The page in front, not a tab hidden behind it or a page in another pane.
      const field = document.querySelector('[data-front-tab] input[type="search"]');
      if (!field) return false;
      field.focus();
      field.select();
    },
  });
}
