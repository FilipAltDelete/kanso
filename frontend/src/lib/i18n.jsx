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
    'common.loading': 'Loading',
    'form.save': 'Save',
    'form.saving': 'Saving…',
    'form.cancel': 'Cancel',
    'form.fixErrors': 'Some fields need attention.',
    'violation.required': 'This field is required.',
    'violation.invalid_email': 'This is not a valid email address.',
    'violation.duplicate': 'A customer with this email already exists.',
    'violation.invalid_phone': 'This is not a valid phone number.',
    'violation.too_long': 'This is too long.',
    'violation.invalid_choice': 'Pick one of the options.',
    'violation.invalid_country': 'Pick a country.',
    'violation.duplicate_default': 'Only one address of this type can be the default.',
    'violation.unknown_address': 'This address belongs to another customer.',
    'violation.too_many': 'There are too many addresses.',
    'customers.title': 'Customers',
    'customers.subtitle': 'Everyone who has bought, or can buy, from the store.',
    'customers.new': 'New customer',
    'customers.create': 'Create customer',
    'customers.edit': 'Edit',
    'customers.editTitle': 'Edit {name}',
    'customers.empty': 'No customers yet.',
    'customers.back': 'All customers',
    'customers.notFound': 'This customer does not exist.',
    'customer.name': 'Name',
    'customer.email': 'Email',
    'customer.phone': 'Phone',
    'customer.location': 'Location',
    'customer.createdAt': 'Created',
    'customer.updatedAt': 'Last changed',
    'customer.details': 'Details',
    'customer.addresses': 'Addresses',
    'customer.addresses.shipping': 'Shipping addresses',
    'customer.addresses.billing': 'Billing addresses',
    'customer.addresses.none': 'No addresses.',
    'customer.address.default': 'Default',
    'customer.address.makeDefault': 'Default for its type',
    'customer.address.addShipping': 'Add shipping address',
    'customer.address.addBilling': 'Add billing address',
    'customer.address.legend': '{type} address {number}',
    'customer.address.remove': 'Remove {address}',
    'customer.address.type': 'Type',
    'customer.address.type.shipping': 'Shipping',
    'customer.address.type.billing': 'Billing',
    'customer.address.name': 'Recipient',
    'customer.address.company': 'Company',
    'customer.address.line1': 'Address line 1',
    'customer.address.line2': 'Address line 2',
    'customer.address.postalCode': 'Postal code',
    'customer.address.city': 'City',
    'customer.address.region': 'Region',
    'customer.address.phone': 'Phone',
    'customer.address.country': 'Country',
    'customer.history': 'History',
    'customer.history.created': 'Created',
    'customer.history.updated': 'Changed',
    'customer.history.by': 'by {actor}',
    'customer.history.fieldChanged': '{field}: {before} → {after}',
    'customer.history.addressesChanged': 'Addresses changed',
    'customer.history.empty': '(empty)',
    'customer.orders': 'Orders',
    'customer.ordersPlaceholderTitle': 'Order history comes with orders',
    'customer.ordersPlaceholderBody': "This customer's orders will be listed here once orders are linked to customers.",
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
    'common.loading': 'Laddar',
    'form.save': 'Spara',
    'form.saving': 'Sparar…',
    'form.cancel': 'Avbryt',
    'form.fixErrors': 'Några fält behöver åtgärdas.',
    'violation.required': 'Fältet är obligatoriskt.',
    'violation.invalid_email': 'Det här är ingen giltig e-postadress.',
    'violation.duplicate': 'Det finns redan en kund med den här e-postadressen.',
    'violation.invalid_phone': 'Det här är inget giltigt telefonnummer.',
    'violation.too_long': 'Det här är för långt.',
    'violation.invalid_choice': 'Välj ett av alternativen.',
    'violation.invalid_country': 'Välj ett land.',
    'violation.duplicate_default': 'Bara en adress av den här typen kan vara förvald.',
    'violation.unknown_address': 'Adressen tillhör en annan kund.',
    'violation.too_many': 'Det är för många adresser.',
    'customers.title': 'Kunder',
    'customers.subtitle': 'Alla som har handlat, eller kan handla, i butiken.',
    'customers.new': 'Ny kund',
    'customers.create': 'Skapa kund',
    'customers.edit': 'Redigera',
    'customers.editTitle': 'Redigera {name}',
    'customers.empty': 'Inga kunder ännu.',
    'customers.back': 'Alla kunder',
    'customers.notFound': 'Kunden finns inte.',
    'customer.name': 'Namn',
    'customer.email': 'E-post',
    'customer.phone': 'Telefon',
    'customer.location': 'Ort',
    'customer.createdAt': 'Skapad',
    'customer.updatedAt': 'Senast ändrad',
    'customer.details': 'Uppgifter',
    'customer.addresses': 'Adresser',
    'customer.addresses.shipping': 'Leveransadresser',
    'customer.addresses.billing': 'Fakturaadresser',
    'customer.addresses.none': 'Inga adresser.',
    'customer.address.default': 'Förvald',
    'customer.address.makeDefault': 'Förvald för sin typ',
    'customer.address.addShipping': 'Lägg till leveransadress',
    'customer.address.addBilling': 'Lägg till fakturaadress',
    'customer.address.legend': '{type}adress {number}',
    'customer.address.remove': 'Ta bort {address}',
    'customer.address.type': 'Typ',
    'customer.address.type.shipping': 'Leverans',
    'customer.address.type.billing': 'Faktura',
    'customer.address.name': 'Mottagare',
    'customer.address.company': 'Företag',
    'customer.address.line1': 'Adressrad 1',
    'customer.address.line2': 'Adressrad 2',
    'customer.address.postalCode': 'Postnummer',
    'customer.address.city': 'Ort',
    'customer.address.region': 'Region',
    'customer.address.phone': 'Telefon',
    'customer.address.country': 'Land',
    'customer.history': 'Historik',
    'customer.history.created': 'Skapad',
    'customer.history.updated': 'Ändrad',
    'customer.history.by': 'av {actor}',
    'customer.history.fieldChanged': '{field}: {before} → {after}',
    'customer.history.addressesChanged': 'Adresserna ändrades',
    'customer.history.empty': '(tomt)',
    'customer.orders': 'Ordrar',
    'customer.ordersPlaceholderTitle': 'Orderhistoriken kommer med ordrarna',
    'customer.ordersPlaceholderBody': 'Kundens ordrar visas här när ordrar kopplas till kunder.',
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
