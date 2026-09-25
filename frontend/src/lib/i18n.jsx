import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/**
 * Swedish and English from day one (CLAUDE.md): every user-facing string goes
 * through t(). A key missing in one language falls back to English, then to
 * the key itself, so a gap is visible rather than blank.
 */
export const messages = {
  en: {
    'app.name': 'Kanso OMS',
    'auth.subtitle': 'Sign in to continue.',
    'auth.email': 'Email',
    'auth.password': 'Password',
    'auth.signIn': 'Sign in',
    'auth.signingIn': 'Signing in…',
    'auth.signOut': 'Sign out',
    'auth.restoring': 'Restoring your session',
    'nav.main': 'Main navigation',
    'nav.dashboard': 'Dashboard',
    'nav.orders': 'Orders',
    'nav.inventory': 'Inventory',
    'nav.products': 'Products',
    'nav.customers': 'Customers',
    'nav.comingSoon': 'Phase 1',
    'dashboard.title': 'Dashboard',
    'dashboard.subtitle': 'Today at a glance.',
    'kpi.ordersToday': 'Orders today',
    'kpi.awaitingFulfillment': 'Awaiting fulfillment',
    'kpi.shippedToday': 'Shipped today',
    'kpi.lowStock': 'Low stock SKUs',
    'dashboard.emptyTitle': 'No orders yet',
    'dashboard.emptyBody': 'Orders appear here once they are created or imported (Phase 1).',
    'notFound.title': 'Page not found',
    'notFound.back': 'Back to the dashboard',
    'table.search': 'Search',
    'table.searchPlaceholder': 'Search…',
    'table.filterAll': 'All',
    'table.resetView': 'Reset view',
    'table.selectAll': 'Select all rows on this page',
    'table.selectRow': 'Select {row}',
    'table.selected': '{count} selected',
    'table.clearSelection': 'Clear selection',
    'table.bulkActions': 'Bulk actions',
    'table.empty': 'Nothing here yet.',
    'table.noMatches': 'No rows match the search or filters.',
    'table.pagination': 'Pagination',
    'table.range': '{from}–{to} of {total}',
    'table.page': 'Page {page} of {pages}',
    'table.pageSize': 'Rows per page',
    'table.firstPage': 'First page',
    'table.previousPage': 'Previous page',
    'table.nextPage': 'Next page',
    'table.lastPage': 'Last page',
    'table.keyboardHelp': 'Arrow keys, Home, End, Page Up and Page Down move between cells. Enter opens a row. / jumps to the search.',
    'table.keyboardHelpSelectable':
      'Arrow keys, Home, End, Page Up and Page Down move between cells. Space selects a row, Enter opens it, Ctrl+A selects the page and Escape clears the selection. / jumps to the search.',
    'order.number': 'Order',
    'order.customer': 'Customer',
    'order.channel': 'Channel',
    'order.status': 'Status',
    'order.lines': 'Lines',
    'order.total': 'Total',
    'order.createdAt': 'Created',
    'orderStatus.pending': 'Pending',
    'orderStatus.confirmed': 'Confirmed',
    'orderStatus.allocated': 'Allocated',
    'orderStatus.picking': 'Picking',
    'orderStatus.packed': 'Packed',
    'orderStatus.shipped': 'Shipped',
    'orderStatus.delivered': 'Delivered',
    'orderStatus.cancelled': 'Cancelled',
    'orderStatus.on_hold': 'On hold',
    'tableDemo.title': 'Table demo',
    'tableDemo.subtitle': 'Generated orders, no API behind this page. The view is kept in the URL: bookmark or share it.',
    'tableDemo.caption': 'Demo orders',
    'tableDemo.printPickLists': 'Print pick lists',
    'tableDemo.export': 'Export',
    'tableDemo.actionDone': '{action}: {count} orders (demo, nothing was sent).',
    'tableDemo.opened': 'Would open {number} (demo).',
    'language.label': 'Language',
    'nav.settings': 'Settings',
    'settings.title': 'Settings',
    'settings.subtitle': 'Personal preferences for how Kanso looks.',
    'settings.theme': 'Theme',
    'settings.rememberedHere': 'Remembered in this browser.',
  },
  sv: {
    'app.name': 'Kanso OMS',
    'auth.subtitle': 'Logga in för att fortsätta.',
    'auth.email': 'E-post',
    'auth.password': 'Lösenord',
    'auth.signIn': 'Logga in',
    'auth.signingIn': 'Loggar in…',
    'auth.signOut': 'Logga ut',
    'auth.restoring': 'Återställer din session',
    'nav.main': 'Huvudmeny',
    'nav.dashboard': 'Översikt',
    'nav.orders': 'Ordrar',
    'nav.inventory': 'Lager',
    'nav.products': 'Produkter',
    'nav.customers': 'Kunder',
    'nav.comingSoon': 'Fas 1',
    'dashboard.title': 'Översikt',
    'dashboard.subtitle': 'Dagens läge i korthet.',
    'kpi.ordersToday': 'Ordrar idag',
    'kpi.awaitingFulfillment': 'Väntar på plock',
    'kpi.shippedToday': 'Skickade idag',
    'kpi.lowStock': 'Artiklar med lågt lager',
    'dashboard.emptyTitle': 'Inga ordrar än',
    'dashboard.emptyBody': 'Ordrar visas här när de skapas eller importeras (fas 1).',
    'notFound.title': 'Sidan finns inte',
    'notFound.back': 'Tillbaka till översikten',
    'table.search': 'Sök',
    'table.searchPlaceholder': 'Sök…',
    'table.filterAll': 'Alla',
    'table.resetView': 'Återställ vy',
    'table.selectAll': 'Markera alla rader på sidan',
    'table.selectRow': 'Markera {row}',
    'table.selected': '{count} markerade',
    'table.clearSelection': 'Avmarkera alla',
    'table.bulkActions': 'Massåtgärder',
    'table.empty': 'Inget här än.',
    'table.noMatches': 'Inga rader matchar sökningen eller filtren.',
    'table.pagination': 'Sidnavigering',
    'table.range': '{from}–{to} av {total}',
    'table.page': 'Sida {page} av {pages}',
    'table.pageSize': 'Rader per sida',
    'table.firstPage': 'Första sidan',
    'table.previousPage': 'Föregående sida',
    'table.nextPage': 'Nästa sida',
    'table.lastPage': 'Sista sidan',
    'table.keyboardHelp': 'Piltangenterna, Home, End, Page Up och Page Down flyttar mellan celler. Enter öppnar en rad. / går till sökfältet.',
    'table.keyboardHelpSelectable':
      'Piltangenterna, Home, End, Page Up och Page Down flyttar mellan celler. Mellanslag markerar en rad, Enter öppnar den, Ctrl+A markerar sidan och Escape avmarkerar. / går till sökfältet.',
    'order.number': 'Order',
    'order.customer': 'Kund',
    'order.channel': 'Kanal',
    'order.status': 'Status',
    'order.lines': 'Rader',
    'order.total': 'Summa',
    'order.createdAt': 'Skapad',
    'orderStatus.pending': 'Väntande',
    'orderStatus.confirmed': 'Bekräftad',
    'orderStatus.allocated': 'Allokerad',
    'orderStatus.picking': 'Plockas',
    'orderStatus.packed': 'Packad',
    'orderStatus.shipped': 'Skickad',
    'orderStatus.delivered': 'Levererad',
    'orderStatus.cancelled': 'Annullerad',
    'orderStatus.on_hold': 'Pausad',
    'tableDemo.title': 'Tabelldemo',
    'tableDemo.subtitle': 'Genererade ordrar, inget API bakom sidan. Vyn sparas i adressen: bokmärk eller dela den.',
    'tableDemo.caption': 'Demoordrar',
    'tableDemo.printPickLists': 'Skriv ut plocklistor',
    'tableDemo.export': 'Exportera',
    'tableDemo.actionDone': '{action}: {count} ordrar (demo, inget skickades).',
    'tableDemo.opened': 'Skulle öppna {number} (demo).',
    'language.label': 'Språk',
    'nav.settings': 'Inställningar',
    'settings.title': 'Inställningar',
    'settings.subtitle': 'Personliga val för hur Kanso ser ut.',
    'settings.theme': 'Tema',
    'settings.rememberedHere': 'Sparas i den här webbläsaren.',
  },
};

export const locales = [
  { code: 'sv', label: 'Svenska' },
  { code: 'en', label: 'English' },
];

const STORAGE_KEY = 'kanso.locale';

/**
 * `{name}` placeholders are filled from `values`. Format numbers and dates
 * for the locale before passing them in; a placeholder with no value stays
 * visible.
 */
export function translate(locale, key, values) {
  const message = messages[locale]?.[key] ?? messages.en[key] ?? key;
  if (!values) return message;

  return message.replace(/\{(\w+)\}/g, (placeholder, name) => (name in values ? String(values[name]) : placeholder));
}

function initialLocale() {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    if (saved in messages) return saved;
  } catch {
    // Storage can be unavailable (private mode); the browser language decides.
  }
  return navigator.language?.toLowerCase().startsWith('sv') ? 'sv' : 'en';
}

const I18nContext = createContext(null);

export function I18nProvider({ children, locale: fixedLocale }) {
  const [locale, setLocaleState] = useState(() => fixedLocale ?? initialLocale());

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((next) => {
    setLocaleState(next);
    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // A preference that cannot be stored lasts for this page load.
    }
  }, []);

  const value = useMemo(() => ({ locale, setLocale, t: (key, values) => translate(locale, key, values) }), [locale, setLocale]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const context = useContext(I18nContext);
  if (context === null) throw new Error('useI18n must be used inside an I18nProvider.');

  return context;
}
