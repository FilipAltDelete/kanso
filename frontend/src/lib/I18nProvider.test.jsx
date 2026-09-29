import { fireEvent, render, screen } from '@testing-library/react';

/**
 * The test setup downloads both catalogs up front; these tests take a fresh
 * copy of the i18n module, with none, to see what the app downloads and when.
 */
async function fresh({ failing = [] } = {}) {
  vi.resetModules();
  for (const locale of failing) {
    vi.doMock(`./messages/${locale}.js`, () => {
      throw new Error('Failed to fetch dynamically imported module');
    });
  }

  const i18n = await import('./i18n.jsx');
  const { LanguageSelect } = await import('../app/LanguageSelect.jsx');
  const { AppErrorBoundary } = await import('../app/errors.jsx');

  function Probe() {
    const { t } = i18n.useI18n();
    return <p>{t('auth.signIn')}</p>;
  }

  return { ...i18n, LanguageSelect, AppErrorBoundary, Probe };
}

beforeEach(() => window.localStorage.clear());

afterEach(() => {
  vi.doUnmock('./messages/en.js');
  vi.doUnmock('./messages/sv.js');
  vi.restoreAllMocks();
});

describe('the language catalogs', () => {
  it('downloads only the language in use, and the other one when switched to', async () => {
    const { I18nProvider, LanguageSelect, Probe, translate } = await fresh();

    const view = render(
      <I18nProvider locale="sv">
        <Probe />
        <LanguageSelect />
      </I18nProvider>,
    );
    // Nothing until the catalog is in: no keys, no English.
    expect(view.container.textContent).toBe('');
    expect(await screen.findByText('Logga in')).toBeTruthy();
    expect(translate('en', 'auth.signIn')).toBe('auth.signIn');

    fireEvent.change(screen.getByLabelText('Språk'), { target: { value: 'en' } });

    expect(await screen.findByText('Sign in')).toBeTruthy();
    expect(document.documentElement.lang).toBe('en');
    expect(window.localStorage.getItem('kanso.locale')).toBe('en');
  });

  it('stays in the language on screen, and says so, when the other one cannot be downloaded', async () => {
    const { I18nProvider, LanguageSelect, Probe } = await fresh({ failing: ['en'] });

    render(
      <I18nProvider locale="sv">
        <Probe />
        <LanguageSelect />
      </I18nProvider>,
    );
    await screen.findByText('Logga in');

    fireEvent.change(screen.getByLabelText('Språk'), { target: { value: 'en' } });

    expect((await screen.findByRole('alert')).textContent).toBe('Språket kunde inte laddas. Försök igen.');
    expect(screen.getByText('Logga in')).toBeTruthy();
    expect(screen.getByLabelText('Språk')).toHaveProperty('value', 'sv');
    expect(window.localStorage.getItem('kanso.locale')).toBeNull();
  });

  it('leaves it to the last-resort boundary when no catalog can be downloaded at all', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.localStorage.setItem('kanso.locale', 'en');
    const { AppErrorBoundary, I18nProvider, Probe } = await fresh({ failing: ['en'] });

    render(
      <AppErrorBoundary>
        <I18nProvider>
          <Probe />
        </I18nProvider>
      </AppErrorBoundary>,
    );

    expect(await screen.findByRole('heading', { name: 'Something went wrong' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Reload' })).toBeTruthy();
  });
});
