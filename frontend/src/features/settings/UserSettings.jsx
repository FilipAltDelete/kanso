import { useContext, useMemo, useRef, useState } from 'react';
import { Plus, UserPlus } from 'lucide-react';
import {
  PASSWORD_MIN,
  USER_ROLES,
  useActivateUser,
  useCreateUser,
  useDeactivateUser,
  useSetUserPassword,
  useUpdateUser,
  useUsers,
} from '../../api/users.js';
import { Badge, Button, Dialog, ErrorNotice, Field, Input, Select } from '../../components/ui/primitives.jsx';
import { DataTable, EMPTY_VIEW } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';
import { AuthContext } from '../auth/AuthProvider.jsx';

const EMAIL_MAX = 180;
const NAME_MAX = 128;
const STATUS_TONES = { active: 'green', deactivated: 'slate' };
const DEFAULT_VIEW = { ...EMPTY_VIEW, sorting: [{ id: 'email', desc: false }], pageSize: 10 };

/** A 422's or 409's violations as `{ field: message }`: `users.violation.<field>.<code>` where known, else the server's message. */
export function serverFieldErrors(error, t) {
  return Object.fromEntries(
    ([409, 422].includes(error?.status) ? error.violations : [])
      .filter((violation) => violation.path)
      .map((violation) => [violation.path, firstTranslation(t, [`users.violation.${violation.path}.${violation.code}`], violation.message)]),
  );
}

/** A problem that is about no one field, translated by its code where known. */
function ProblemNotice({ error }) {
  const { t } = useI18n();
  if (!error) return null;

  const general = (error.violations ?? []).find((violation) => !violation.path);
  if (general) {
    const message = firstTranslation(t, [`users.problem.${general.code}`], null);
    if (message) {
      return (
        <p role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          {message}
        </p>
      );
    }
  }

  return [409, 422].includes(error.status) && Object.keys(serverFieldErrors(error, t)).length > 0 ? null : <ErrorNotice error={error} />;
}

/**
 * The people who sign in, for admins only (as the API has it): add someone
 * with a first password to pass on, change their role, set a forgotten
 * password, and deactivate someone who has left. The server keeps at least
 * one active admin; nobody deactivates themselves.
 */
export function UserSettings() {
  // Tolerates a missing provider, like the API keys: the page's own tests render it bare.
  const user = useContext(AuthContext)?.user;
  if (!user?.roles?.includes('ROLE_ADMIN')) return null;

  return <Users me={user} />;
}

function Users({ me }) {
  const { t, locale } = useI18n();
  const [view, setView] = useState(DEFAULT_VIEW);
  const [status, setStatus] = useState('');
  const users = useUsers(view, status);
  const activate = useActivateUser();
  // { kind: 'create' | 'edit' | 'password' | 'deactivate', user }
  const [dialog, setDialog] = useState(null);
  const close = () => setDialog(null);

  const date = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }), [locale]);

  const columns = useMemo(
    () => [
      {
        accessorKey: 'email',
        header: t('users.user'),
        cell: ({ row }) => (
          <>
            <span className="block font-medium">
              {row.original.name ?? row.original.email}
              {row.original.id === me.id ? <span className="ml-1 text-xs font-normal text-slate-500">{t('users.you')}</span> : null}
            </span>
            {row.original.name ? <span className="block text-xs text-slate-500">{row.original.email}</span> : null}
          </>
        ),
      },
      { accessorKey: 'role', header: t('users.role'), enableSorting: false, cell: ({ getValue }) => t(`users.role.${getValue()}`) },
      {
        accessorKey: 'status',
        header: t('users.status'),
        enableSorting: false,
        cell: ({ getValue }) => <Badge tone={STATUS_TONES[getValue()]}>{t(`users.status.${getValue()}`)}</Badge>,
      },
      {
        accessorKey: 'createdAt',
        header: t('users.created'),
        cell: ({ getValue }) => <time dateTime={getValue()}>{date.format(new Date(getValue()))}</time>,
      },
      {
        id: 'actions',
        header: t('common.actions'),
        enableSorting: false,
        meta: { label: t('common.actions'), align: 'end' },
        cell: ({ row }) => {
          const user = row.original;
          const who = user.name ?? user.email;

          return (
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setDialog({ kind: 'edit', user })} aria-label={t('users.editNamed', { name: who })}>
                {t('users.edit')}
              </Button>
              {user.id === me.id ? null : (
                <Button variant="outline" size="sm" onClick={() => setDialog({ kind: 'password', user })} aria-label={t('users.setPasswordNamed', { name: who })}>
                  {t('users.setPassword')}
                </Button>
              )}
              {user.id === me.id ? null : user.status === 'active' ? (
                <Button variant="outline" size="sm" onClick={() => setDialog({ kind: 'deactivate', user })} aria-label={t('users.deactivateNamed', { name: who })}>
                  {t('users.deactivate')}
                </Button>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  disabled={activate.isPending}
                  onClick={() => activate.mutate(user.id)}
                  aria-label={t('users.activateNamed', { name: who })}
                >
                  {t('users.activate')}
                </Button>
              )}
            </div>
          );
        },
      },
    ],
    [t, date, me.id, activate],
  );

  return (
    <section aria-labelledby="users-heading" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="users-heading" className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('users.title')}
          </h2>
          <p className="text-sm text-slate-500">{t('users.subtitle')}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select
            aria-label={t('users.show')}
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setView((current) => ({ ...current, pageIndex: 0 }));
            }}
          >
            <option value="">{t('users.show.all')}</option>
            <option value="active">{t('users.show.active')}</option>
            <option value="deactivated">{t('users.show.deactivated')}</option>
          </Select>
          <Button size="sm" onClick={() => setDialog({ kind: 'create' })}>
            <Plus className="size-4" aria-hidden="true" />
            {t('users.new')}
          </Button>
        </div>
      </div>
      {users.error ? <ErrorNotice error={users.error} /> : null}
      {activate.error ? <ProblemNotice error={activate.error} /> : null}
      <DataTable
        view={view}
        onViewChange={setView}
        manual
        label={t('users.title')}
        data={users.data?.member ?? []}
        rowCount={users.data?.totalItems ?? 0}
        loading={users.isPending}
        columns={columns}
        getRowId={(user) => user.id}
        emptyMessage={t('users.empty')}
      />
      {dialog?.kind === 'create' ? <UserFormDialog onClose={close} /> : null}
      {dialog?.kind === 'edit' ? <UserFormDialog user={dialog.user} onClose={close} /> : null}
      {dialog?.kind === 'password' ? <SetPasswordDialog user={dialog.user} onClose={close} /> : null}
      {dialog?.kind === 'deactivate' ? <DeactivateDialog user={dialog.user} onClose={close} /> : null}
    </section>
  );
}

/** Adds someone (with a first password), or changes someone's email, name and role. */
function UserFormDialog({ user, onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const creating = !user;
  const create = useCreateUser();
  const update = useUpdateUser();
  const mutation = creating ? create : update;
  const [values, setValues] = useState({ email: user?.email ?? '', name: user?.name ?? '', role: user?.role ?? 'ROLE_OPERATOR', password: '' });
  const [touched, setTouched] = useState(false);

  const problems = {
    email: values.email.trim() === '' ? t('users.violation.email.required') : null,
    password: creating && values.password.length < PASSWORD_MIN ? t('users.violation.password.too_short', { min: PASSWORD_MIN }) : null,
  };
  const shown = touched ? Object.fromEntries(Object.entries(problems).filter(([, message]) => message)) : {};
  const errors = { ...serverFieldErrors(mutation.error, t), ...shown };
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (Object.values(problems).some(Boolean) || mutation.isPending) return;

    const details = { email: values.email.trim(), name: values.name.trim() === '' ? null : values.name.trim(), role: values.role };
    const done = { onSuccess: () => dialogRef.current?.close() };
    if (creating) create.mutate({ ...details, password: values.password }, done);
    else update.mutate({ id: user.id, ...details }, done);
  }

  return (
    <Dialog
      dialogRef={dialogRef}
      title={creating ? t('users.newTitle') : t('users.editTitle', { name: user.name ?? user.email })}
      description={creating ? t('users.newSubtitle') : null}
      onClose={onClose}
      className="max-w-lg"
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        <ProblemNotice error={mutation.error} />
        <Field label={t('users.email')} error={errors.email} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} type="email" autoComplete="off" maxLength={EMAIL_MAX} value={values.email} onChange={set('email')} />}
        </Field>
        <Field label={t('users.name')} error={errors.name}>
          {(props) => <Input {...props} autoComplete="off" maxLength={NAME_MAX} value={values.name} onChange={set('name')} />}
        </Field>
        <Field label={t('users.role')} hint={t(`users.roleHint.${values.role}`)} error={errors.role} required requiredLabel={t('catalog.required')}>
          {(props) => (
            <Select {...props} value={values.role} onChange={set('role')} className="w-full">
              {USER_ROLES.map((role) => (
                <option key={role} value={role}>
                  {t(`users.role.${role}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {creating ? (
          <Field
            label={t('users.firstPassword')}
            hint={t('users.firstPasswordHint', { min: PASSWORD_MIN })}
            error={errors.password}
            required
            requiredLabel={t('catalog.required')}
          >
            {(props) => <Input {...props} type="password" autoComplete="new-password" value={values.password} onChange={set('password')} />}
          </Field>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {creating ? <UserPlus className="size-4" aria-hidden="true" /> : null}
            {mutation.isPending ? t('users.saving') : creating ? t('users.create') : t('users.save')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function SetPasswordDialog({ user, onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const setPassword = useSetUserPassword();
  const [password, setValue] = useState('');
  const [touched, setTouched] = useState(false);

  const problem = password.length < PASSWORD_MIN ? t('users.violation.password.too_short', { min: PASSWORD_MIN }) : null;
  const error = (touched ? problem : null) ?? serverFieldErrors(setPassword.error, t).password;

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (problem || setPassword.isPending) return;
    setPassword.mutate({ id: user.id, password }, { onSuccess: () => dialogRef.current?.close() });
  }

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t('users.setPasswordTitle', { name: user.name ?? user.email })}
      description={t('users.setPasswordSubtitle')}
      onClose={onClose}
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        <ProblemNotice error={setPassword.error} />
        <Field label={t('users.newPassword')} hint={t('users.firstPasswordHint', { min: PASSWORD_MIN })} error={error} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} type="password" autoComplete="new-password" value={password} onChange={(event) => setValue(event.target.value)} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={setPassword.isPending}>
            {t('users.setPassword')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function DeactivateDialog({ user, onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const deactivate = useDeactivateUser();

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t('users.deactivateTitle', { name: user.name ?? user.email })}
      description={t('users.deactivateWarning')}
      onClose={onClose}
    >
      <ProblemNotice error={deactivate.error} />
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
          {t('common.cancel')}
        </Button>
        <Button
          type="button"
          variant="danger"
          disabled={deactivate.isPending}
          onClick={() => deactivate.mutate(user.id, { onSuccess: () => dialogRef.current?.close() })}
        >
          {t('users.deactivate')}
        </Button>
      </div>
    </Dialog>
  );
}
