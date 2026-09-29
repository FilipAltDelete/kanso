import { Component } from 'react';
import { useRouter } from '@tanstack/react-router';
import { RotateCw, TriangleAlert } from 'lucide-react';
import { Button } from '../components/ui/primitives.jsx';
import { preferredLocale, translate, useI18n } from '../lib/i18n.jsx';

/**
 * Where a render error stops (ADR-0020), from the inside out:
 *
 * - a page: the router's `errorComponent` (RouteError) shows PageError in
 *   place of the page, in its own tab;
 * - a workspace tab: an ErrorBoundary around each tab's router catches what
 *   escapes the router, so one tab failing leaves the others working;
 * - the app: AppErrorBoundary, the last resort, which needs no catalog.
 */

/** Shows `fallback({ error, reset })` in place of children that threw while rendering; `reset` tries them again. */
export class ErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  reset = () => this.setState({ error: null });

  render() {
    const { error } = this.state;

    return error ? this.props.fallback({ error, reset: this.reset }) : this.props.children;
  }
}

/** The error's own words, folded away: for whoever the problem is reported to. */
function ErrorDetails({ error, label }) {
  if (!error?.message) return null;

  return (
    <details className="text-xs text-slate-500">
      <summary className="cursor-pointer rounded focus-visible:outline-2 focus-visible:outline-slate-900">{label}</summary>
      <pre className="mt-1 whitespace-pre-wrap break-words font-mono">{error.message}</pre>
    </details>
  );
}

/** A page that could not be shown, in its tab; the rest of the app carries on. */
export function PageError({ error, onReload }) {
  const { t } = useI18n();

  return (
    <div role="alert" className="max-w-xl space-y-3 rounded-lg border border-red-200 bg-white p-5">
      <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
        <TriangleAlert aria-hidden="true" className="size-5 text-red-600" />
        {t('error.page.title')}
      </h1>
      <p className="text-sm text-slate-600">{t('error.page.body')}</p>
      <Button size="sm" onClick={onReload}>
        <RotateCw aria-hidden="true" className="size-4" />
        {t('error.page.reload')}
      </Button>
      <ErrorDetails error={error} label={t('error.details')} />
    </div>
  );
}

/**
 * The router's `errorComponent`. Reloading renders the page again with its
 * route matched afresh; a page that fails again shows this again.
 */
export function RouteError({ error, reset }) {
  const router = useRouter();

  return (
    <PageError
      error={error}
      onReload={() => {
        reset();
        router.invalidate();
      }}
    />
  );
}

/**
 * The last resort, around everything, the language catalog included: it
 * speaks from the essentials in lib/i18n.jsx, and reloading the browser page
 * is all it offers. The open tabs survive that; they are kept in the browser.
 */
export class AppErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;

    const locale = preferredLocale();
    const t = (key) => translate(locale, key);

    return (
      <main className="flex min-h-full items-center justify-center bg-slate-50 p-4">
        <div role="alert" className="w-full max-w-md space-y-3 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-lg font-semibold text-slate-900">{t('app.failed.title')}</h1>
          <p className="text-sm text-slate-600">{t('app.failed.body')}</p>
          <Button onClick={() => window.location.reload()}>
            <RotateCw aria-hidden="true" className="size-4" />
            {t('app.failed.reload')}
          </Button>
          <ErrorDetails error={error} label={t('error.details')} />
        </div>
      </main>
    );
  }
}
