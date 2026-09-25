import { useContext, useState } from 'react';
import { PASSWORD_MIN, useChangeOwnPassword } from '../../api/users.js';
import { Button, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';
import { AuthContext } from '../auth/AuthProvider.jsx';

const EMPTY = { currentPassword: '', newPassword: '', repeat: '' };

/**
 * The signed-in person changes their own password. Every other session of
 * theirs ends; this one goes on.
 */
export function PasswordSettings() {
  // Tolerates a missing provider: the page's own tests render it bare.
  const signedIn = Boolean(useContext(AuthContext)?.user);
  if (!signedIn) return null;

  return <ChangePassword />;
}

function ChangePassword() {
  const { t } = useI18n();
  const change = useChangeOwnPassword();
  const [values, setValues] = useState(EMPTY);
  const [touched, setTouched] = useState(false);

  const problems = {
    currentPassword: values.currentPassword === '' ? t('password.violation.currentPassword.required') : null,
    newPassword: values.newPassword.length < PASSWORD_MIN ? t('password.violation.newPassword.too_short', { min: PASSWORD_MIN }) : null,
    repeat: values.repeat !== values.newPassword ? t('password.violation.repeat.mismatch') : null,
  };
  const server = Object.fromEntries(
    (change.error?.status === 422 ? change.error.violations : []).map((violation) => [
      violation.path,
      firstTranslation(t, [`password.violation.${violation.path}.${violation.code}`], violation.message, { min: PASSWORD_MIN }),
    ]),
  );
  const errors = { ...server, ...(touched ? Object.fromEntries(Object.entries(problems).filter(([, message]) => message)) : {}) };
  const set = (field) => (event) => {
    setValues((current) => ({ ...current, [field]: event.target.value }));
    if (change.isSuccess) change.reset();
  };

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (Object.values(problems).some(Boolean) || change.isPending) return;

    change.mutate(
      { currentPassword: values.currentPassword, newPassword: values.newPassword },
      {
        onSuccess: () => {
          setValues(EMPTY);
          setTouched(false);
        },
      },
    );
  }

  return (
    <section aria-labelledby="password-heading" className="space-y-3">
      <div>
        <h2 id="password-heading" className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
          {t('password.title')}
        </h2>
        <p className="text-sm text-slate-500">{t('password.subtitle')}</p>
      </div>
      <form onSubmit={submit} noValidate className="max-w-sm space-y-4">
        {change.error && change.error.status !== 422 ? <ErrorNotice error={change.error} /> : null}
        <Field label={t('password.current')} error={errors.currentPassword} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} type="password" autoComplete="current-password" value={values.currentPassword} onChange={set('currentPassword')} />}
        </Field>
        <Field label={t('password.new')} hint={t('password.newHint', { min: PASSWORD_MIN })} error={errors.newPassword} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} type="password" autoComplete="new-password" value={values.newPassword} onChange={set('newPassword')} />}
        </Field>
        <Field label={t('password.repeat')} error={errors.repeat} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} type="password" autoComplete="new-password" value={values.repeat} onChange={set('repeat')} />}
        </Field>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" size="sm" disabled={change.isPending}>
            {change.isPending ? t('password.changing') : t('password.change')}
          </Button>
          <p role="status" className="text-sm text-green-700">
            {change.isSuccess ? t('password.changed') : ''}
          </p>
        </div>
      </form>
    </section>
  );
}
