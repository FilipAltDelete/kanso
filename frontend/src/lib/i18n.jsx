import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/**
 * Swedish and English from day one (CLAUDE.md): every user-facing string goes
 * through t(). A key missing in one language falls back to English, then to
 * the key itself, so a gap is visible rather than blank.
 *
 * Only the language in use is downloaded. Each catalog (lib/messages) is a
 * chunk of its own, fetched when the app starts and when someone switches
 * language (ADR-0020). What the app has to be able to say before a catalog has
 * arrived, or when none can — that it is loading, that it failed — is in
 * `essentials`, in both languages, in the main bundle.
 */

export const locales = [
  { code: 'sv', label: 'Svenska' },
  { code: 'en', label: 'English' },
];

const loaders = {
  en: () => import('./messages/en.js'),
  sv: () => import('./messages/sv.js'),
};

/** Said without a catalog. Kept out of the catalogs, so every string has one home. */
export const essentials = {
  en: {
    'common.loading': 'Loading',
    'error.details': 'Details',
    'app.failed.title': 'Something went wrong',
    'app.failed.body': 'Kanso OMS ran into a problem it could not recover from. Reload to start again; your open tabs are kept.',
    'app.failed.reload': 'Reload',
  },
  sv: {
    'common.loading': 'Laddar',
    'error.details': 'Detaljer',
    'app.failed.title': 'Något gick fel',
    'app.failed.body': 'Kanso OMS stötte på ett fel som inte gick att rätta till. Ladda om för att börja om; dina öppna flikar finns kvar.',
    'app.failed.reload': 'Ladda om',
  },
};

/** The catalogs downloaded so far, and the downloads under way, by locale. */
const catalogs = {};
const downloads = {};

/**
 * The catalog for `locale`, downloaded once. A failed download is forgotten,
 * so the next call tries again.
 */
export function loadMessages(locale) {
  if (catalogs[locale]) return Promise.resolve(catalogs[locale]);
  if (!(locale in loaders)) return Promise.reject(new Error(`No messages for "${locale}".`));

  downloads[locale] ??= loaders[locale]().then(
    (module) => {
      catalogs[locale] = module.default;
      delete downloads[locale];
      return module.default;
    },
    (error) => {
      delete downloads[locale];
      throw error;
    },
  );

  return downloads[locale];
}

const STORAGE_KEY = 'kanso.locale';

/**
 * `{name}` placeholders are filled from `values`. Format numbers and dates
 * for the locale before passing them in; a placeholder with no value stays
 * visible.
 */
export function translate(locale, key, values) {
  const message = catalogs[locale]?.[key] ?? essentials[locale]?.[key] ?? catalogs.en?.[key] ?? essentials.en[key] ?? key;
  if (!values) return message;

  return message.replace(/\{(\w+)\}/g, (placeholder, name) => (name in values ? String(values[name]) : placeholder));
}

/** The language chosen in this browser before, else the browser's own. */
export function preferredLocale() {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    if (saved in loaders) return saved;
  } catch {
    // Storage can be unavailable (private mode); the browser language decides.
  }
  return navigator.language?.toLowerCase().startsWith('sv') ? 'sv' : 'en';
}

const I18nContext = createContext(null);

/**
 * Renders nothing until the first catalog has arrived — a moment, on a page
 * that was blank until the script ran anyway. A catalog that cannot be
 * downloaded is thrown, for the app's last-resort boundary to show in the
 * words it has without one. A switch keeps the language on screen until the
 * next one is in; `setLocale` rejects when it cannot be downloaded.
 */
export function I18nProvider({ children, locale: fixedLocale }) {
  const [locale, setLocaleState] = useState(() => fixedLocale ?? preferredLocale());
  const [ready, setReady] = useState(() => catalogs[locale] !== undefined);
  const [failure, setFailure] = useState(null);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  useEffect(() => {
    if (ready) return undefined;
    let cancelled = false;
    loadMessages(locale).then(
      () => !cancelled && setReady(true),
      (error) => !cancelled && setFailure(error),
    );
    return () => {
      cancelled = true;
    };
    // Only the first language; a switch downloads the next one before it is set.
  }, [ready]); // eslint-disable-line react-hooks/exhaustive-deps

  const setLocale = useCallback(async (next) => {
    await loadMessages(next);
    setLocaleState(next);
    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // A preference that cannot be stored lasts for this page load.
    }
  }, []);

  const value = useMemo(() => ({ locale, setLocale, t: (key, values) => translate(locale, key, values) }), [locale, setLocale]);

  if (failure) throw failure;
  if (!ready) return null;

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const context = useContext(I18nContext);
  if (context === null) throw new Error('useI18n must be used inside an I18nProvider.');

  return context;
}
