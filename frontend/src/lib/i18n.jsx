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
    'language.label': 'Language',
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
    'language.label': 'Språk',
  },
};

export const locales = [
  { code: 'sv', label: 'Svenska' },
  { code: 'en', label: 'English' },
];

const STORAGE_KEY = 'kanso.locale';

export function translate(locale, key) {
  return messages[locale]?.[key] ?? messages.en[key] ?? key;
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

  const value = useMemo(() => ({ locale, setLocale, t: (key) => translate(locale, key) }), [locale, setLocale]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const context = useContext(I18nContext);
  if (context === null) throw new Error('useI18n must be used inside an I18nProvider.');

  return context;
}
