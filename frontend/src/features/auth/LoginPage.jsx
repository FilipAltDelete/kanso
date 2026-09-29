import { useState } from 'react';
import { Button, Card, ErrorNotice, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { LanguageSelect } from '../../app/LanguageSelect.jsx';
import { useAuth } from './AuthProvider.jsx';

export function LoginPage() {
  const { login, expired } = useAuth();
  const { t } = useI18n();
  // After a session ran out, the same person most likely signs in again.
  const [email, setEmail] = useState(expired?.email ?? '');
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await login(email.trim(), password);
    } catch (loginError) {
      setError(loginError);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="flex min-h-full items-center justify-center bg-slate-50 p-4">
      <Card className="w-full max-w-sm p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-lg font-semibold text-slate-900">{t('app.name')}</h1>
            <p className="mt-1 text-sm text-slate-500">{t('auth.subtitle')}</p>
          </div>
          <LanguageSelect />
        </div>

        {expired ? (
          <p role="status" className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('auth.sessionExpired')}
          </p>
        ) : null}

        <form className="mt-6 space-y-4" onSubmit={onSubmit}>
          <div className="space-y-1">
            <label className="text-sm font-medium text-slate-700" htmlFor="email">
              {t('auth.email')}
            </label>
            <Input
              id="email"
              name="email"
              autoComplete="username"
              // The form is the only thing on the page, so focusing it helps;
              // the password, when the email is filled in already.
              // eslint-disable-next-line jsx-a11y/no-autofocus
              autoFocus={!expired}
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>

          <div className="space-y-1">
            <label className="text-sm font-medium text-slate-700" htmlFor="password">
              {t('auth.password')}
            </label>
            <Input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              // eslint-disable-next-line jsx-a11y/no-autofocus
              autoFocus={Boolean(expired)}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>

          {error ? <ErrorNotice error={error} /> : null}

          <Button className="w-full" type="submit" disabled={busy || email === '' || password === ''}>
            {busy ? t('auth.signingIn') : t('auth.signIn')}
          </Button>
        </form>
      </Card>
    </main>
  );
}
