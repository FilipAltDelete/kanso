import { useContext, useMemo, useRef, useState } from 'react';
import { Check, Copy, KeyRound, Plus } from 'lucide-react';
import { API_KEY_ROLES, useApiKeys, useCreateApiKey, useRevokeApiKey } from '../../api/apiKeys.js';
import { Badge, Button, Dialog, ErrorNotice, Field, Input, Select } from '../../components/ui/primitives.jsx';
import { DataTable, EMPTY_VIEW } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';
import { AuthContext } from '../auth/AuthProvider.jsx';

const NAME_MAX = 128;
const STATUS_TONES = { active: 'green', expired: 'amber', revoked: 'slate' };
const DEFAULT_VIEW = { ...EMPTY_VIEW, sorting: [{ id: 'createdAt', desc: true }], pageSize: 10 };

/** A 422's violations as `{ field: message }`: `apiKeys.violation.<field>.<code>` where known, else the server's message. */
function serverFieldErrors(error, t) {
  return Object.fromEntries(
    (error?.status === 422 ? error.violations : []).map((violation) => [
      violation.path,
      firstTranslation(t, [`apiKeys.violation.${violation.path}.${violation.code}`], violation.message),
    ]),
  );
}

/**
 * API keys for integrations, for admins only (as the API has it). A key is
 * shown once, in the dialog that created it; afterwards only its name and
 * use are. Revoking asks first, because an integration stops at once.
 */
export function ApiKeySettings() {
  // Tolerates a missing provider, like the shortcut settings: the page's own
  // tests render it bare.
  const user = useContext(AuthContext)?.user;
  if (!user?.roles?.includes('ROLE_ADMIN')) return null;

  return <ApiKeys />;
}

function ApiKeys() {
  const { t, locale } = useI18n();
  const [view, setView] = useState(DEFAULT_VIEW);
  const keys = useApiKeys(view);
  const [creating, setCreating] = useState(false);
  const [revoking, setRevoking] = useState(null);

  const dateTime = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }), [locale]);

  const columns = useMemo(() => {
    const when = (value) => (value ? <time dateTime={value}>{dateTime.format(new Date(value))}</time> : '—');

    return [
      { accessorKey: 'name', header: t('apiKeys.name') },
      { accessorKey: 'role', header: t('apiKeys.role'), cell: ({ getValue }) => t(`apiKeys.role.${getValue()}`) },
      {
        accessorKey: 'status',
        header: t('apiKeys.status'),
        enableSorting: false,
        cell: ({ getValue }) => <Badge tone={STATUS_TONES[getValue()]}>{t(`apiKeys.status.${getValue()}`)}</Badge>,
      },
      {
        accessorKey: 'createdAt',
        header: t('apiKeys.created'),
        cell: ({ row }) => (
          <>
            {when(row.original.createdAt)}
            <span className="block text-xs text-slate-500">{row.original.createdByName ?? t('apiKeys.createdFromConsole')}</span>
          </>
        ),
      },
      { accessorKey: 'expiresAt', header: t('apiKeys.expires'), cell: ({ getValue }) => when(getValue()) },
      { accessorKey: 'lastUsedAt', header: t('apiKeys.lastUsed'), cell: ({ getValue }) => (getValue() ? when(getValue()) : t('apiKeys.neverUsed')) },
      {
        id: 'actions',
        header: t('common.actions'),
        enableSorting: false,
        meta: { label: t('common.actions'), align: 'end' },
        cell: ({ row }) =>
          row.original.status === 'revoked' ? null : (
            <Button variant="outline" size="sm" onClick={() => setRevoking(row.original)} aria-label={t('apiKeys.revokeNamed', { name: row.original.name })}>
              {t('apiKeys.revoke')}
            </Button>
          ),
      },
    ];
  }, [t, dateTime]);

  return (
    <section aria-labelledby="api-keys-heading" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="api-keys-heading" className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('apiKeys.title')}
          </h2>
          <p className="text-sm text-slate-500">{t('apiKeys.subtitle')}</p>
        </div>
        <Button size="sm" onClick={() => setCreating(true)}>
          <Plus className="size-4" aria-hidden="true" />
          {t('apiKeys.new')}
        </Button>
      </div>
      {keys.error ? <ErrorNotice error={keys.error} /> : null}
      <DataTable
        view={view}
        onViewChange={setView}
        manual
        label={t('apiKeys.title')}
        data={keys.data?.member ?? []}
        rowCount={keys.data?.totalItems ?? 0}
        loading={keys.isPending}
        columns={columns}
        getRowId={(key) => key.id}
        emptyMessage={t('apiKeys.empty')}
      />
      {creating ? <CreateApiKeyDialog onClose={() => setCreating(false)} /> : null}
      {revoking ? <RevokeApiKeyDialog apiKey={revoking} onClose={() => setRevoking(null)} /> : null}
    </section>
  );
}

/** Tomorrow in the browser's calendar, as the date input wants it. */
function tomorrow() {
  const date = new Date();
  date.setDate(date.getDate() + 1);

  return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

/**
 * Name, role and an optional expiry; then the key, once, with a copy button.
 * An expiry date means the start of that day in the browser's time zone.
 */
function CreateApiKeyDialog({ onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const create = useCreateApiKey();
  const [values, setValues] = useState({ name: '', role: 'ROLE_OPERATOR', expiresOn: '' });
  const [touched, setTouched] = useState(false);

  const nameProblem = values.name.trim() === '' ? t('apiKeys.violation.name.required') : null;
  const errors = { ...serverFieldErrors(create.error, t), ...(touched && nameProblem ? { name: nameProblem } : {}) };
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (nameProblem || create.isPending) return;

    create.mutate({
      name: values.name.trim(),
      role: values.role,
      expiresAt: values.expiresOn === '' ? null : new Date(`${values.expiresOn}T00:00`).toISOString(),
    });
  }

  if (create.data) {
    return (
      <Dialog dialogRef={dialogRef} title={t('apiKeys.createdTitle')} description={create.data.name} onClose={onClose} className="max-w-lg">
        <ShowKeyOnce plainKey={create.data.key} onDone={() => dialogRef.current?.close()} />
      </Dialog>
    );
  }

  return (
    <Dialog dialogRef={dialogRef} title={t('apiKeys.newTitle')} description={t('apiKeys.newSubtitle')} onClose={onClose} className="max-w-lg">
      <form onSubmit={submit} noValidate className="space-y-4">
        {create.error && create.error.status !== 422 ? <ErrorNotice error={create.error} /> : null}
        <Field label={t('apiKeys.name')} hint={t('apiKeys.nameHint')} error={errors.name} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} autoComplete="off" maxLength={NAME_MAX} value={values.name} onChange={set('name')} />}
        </Field>
        <Field label={t('apiKeys.role')} hint={t(`apiKeys.roleHint.${values.role}`)} error={errors.role} required requiredLabel={t('catalog.required')}>
          {(props) => (
            <Select {...props} value={values.role} onChange={set('role')} className="w-full">
              {API_KEY_ROLES.map((role) => (
                <option key={role} value={role}>
                  {t(`apiKeys.role.${role}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('apiKeys.expiresOn')} hint={t('apiKeys.expiresHint')} error={errors.expiresAt}>
          {(props) => <Input {...props} type="date" min={tomorrow()} value={values.expiresOn} onChange={set('expiresOn')} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={create.isPending}>
            <KeyRound className="size-4" aria-hidden="true" />
            {create.isPending ? t('apiKeys.creating') : t('apiKeys.create')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function ShowKeyOnce({ plainKey, onDone }) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  const input = useRef(null);

  async function copy() {
    try {
      await navigator.clipboard.writeText(plainKey);
      setCopied(true);
    } catch {
      // No clipboard (plain HTTP, or refused): the key is selected for copying by hand.
      input.current?.select();
    }
  }

  return (
    <div className="space-y-4">
      <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
        {t('apiKeys.onlyOnce')}
      </p>
      <Field label={t('apiKeys.key')}>
        {(props) => (
          <div className="flex gap-2">
            <Input {...props} ref={input} readOnly value={plainKey} onFocus={(event) => event.target.select()} className="font-mono text-xs" />
            <Button type="button" variant="outline" onClick={copy}>
              {copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
              {copied ? t('apiKeys.copied') : t('apiKeys.copy')}
            </Button>
          </div>
        )}
      </Field>
      <p className="text-xs text-slate-500">{t('apiKeys.usage')}</p>
      <div className="flex justify-end">
        <Button type="button" onClick={onDone}>
          {t('apiKeys.done')}
        </Button>
      </div>
    </div>
  );
}

function RevokeApiKeyDialog({ apiKey, onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const revoke = useRevokeApiKey();

  return (
    <Dialog dialogRef={dialogRef} title={t('apiKeys.revokeTitle', { name: apiKey.name })} description={t('apiKeys.revokeWarning')} onClose={onClose}>
      {revoke.error ? <ErrorNotice error={revoke.error} /> : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
          {t('common.cancel')}
        </Button>
        <Button
          type="button"
          variant="danger"
          disabled={revoke.isPending}
          onClick={() => revoke.mutate(apiKey.id, { onSuccess: () => dialogRef.current?.close() })}
        >
          {t('apiKeys.revoke')}
        </Button>
      </div>
    </Dialog>
  );
}
