import { useRef, useState } from 'react';
import { useCreateLocation, useUpdateLocation } from '../../api/inventory.js';
import { Button, Dialog, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { blankToNull, LOCATION_CODE_PATTERN, serverFieldErrors } from './catalogForm.js';

const ADDRESS = ['addressLine1', 'addressLine2', 'postalCode', 'city'];
const LIMITS = { name: 128, addressLine1: 128, addressLine2: 128, postalCode: 16, city: 64 };

const valuesOf = (location) => ({
  code: location?.code ?? '',
  name: location?.name ?? '',
  ...Object.fromEntries(ADDRESS.map((field) => [field, location?.[field] ?? ''])),
  countryCode: location?.countryCode ?? '',
});

/**
 * Creating a location, or editing one when `location` is given. The code is
 * fixed once created. An edit sends the version it was based on; on a 409 the
 * list refetches, `location` arrives in its new version, and the form reloads
 * with the saved values so nothing is overwritten unseen.
 */
export function LocationFormDialog({ location = null, onClose, onSaved }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const editing = location !== null;
  const create = useCreateLocation();
  const update = useUpdateLocation(location?.id);
  const mutation = editing ? update : create;

  const [values, setValues] = useState(() => valuesOf(location));
  const [touched, setTouched] = useState(false);
  const [reloaded, setReloaded] = useState(false);
  const [seenVersion, setSeenVersion] = useState(location?.version);

  if (editing && location.version !== seenVersion) {
    setSeenVersion(location.version);
    if (update.error?.status === 409) {
      setValues(valuesOf(location));
      setReloaded(true);
    }
  }

  const country = values.countryCode.trim().toUpperCase();
  const countryName = /^[A-Z]{2}$/.test(country) ? new Intl.DisplayNames([locale], { type: 'region' }).of(country) : null;

  const problems = {
    code: editing || LOCATION_CODE_PATTERN.test(values.code.trim()) ? null : t('catalog.violation.code.format'),
    name: values.name.trim() === '' ? t('catalog.violation.name.required') : null,
    countryCode: country === '' || /^[A-Z]{2}$/.test(country) ? null : t('catalog.violation.countryCode.format'),
  };
  const valid = Object.values(problems).every((problem) => problem === null);
  const errors = { ...serverFieldErrors(mutation.error, t), ...(touched ? Object.fromEntries(Object.entries(problems).filter(([, problem]) => problem)) : {}) };

  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    setReloaded(false);
    if (!valid || mutation.isPending) return;

    const fields = {
      name: values.name.trim(),
      ...Object.fromEntries(ADDRESS.map((field) => [field, blankToNull(values[field])])),
      countryCode: country === '' ? null : country,
    };
    const body = editing ? { ...fields, version: location.version } : { code: values.code.trim(), ...fields };
    mutation.mutate(body, {
      onSuccess: (saved) => {
        dialogRef.current?.close();
        onSaved?.(saved);
      },
    });
  }

  const text = (field, label, { required = false, hint, autoComplete = 'off' } = {}) => (
    <Field label={label} hint={hint} error={errors[field]} required={required} requiredLabel={t('catalog.required')}>
      {(props) => <Input {...props} autoComplete={autoComplete} maxLength={LIMITS[field]} value={values[field]} onChange={set(field)} />}
    </Field>
  );

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t(editing ? 'locationForm.editTitle' : 'locationForm.newTitle')}
      description={editing ? location.code : t('locationForm.newSubtitle')}
      onClose={onClose}
      className="max-w-lg"
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        {reloaded || mutation.error?.status === 409 ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            {t('locationForm.conflict')}
          </p>
        ) : mutation.error && mutation.error.status !== 422 ? (
          <ErrorNotice error={mutation.error} />
        ) : null}

        <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
          {editing ? null : (
            <Field label={t('location.code')} hint={t('locationForm.codeHint')} error={errors.code} required requiredLabel={t('catalog.required')}>
              {(props) => <Input {...props} autoComplete="off" maxLength={32} value={values.code} onChange={set('code')} />}
            </Field>
          )}
          <div className={editing ? 'sm:col-span-2' : undefined}>{text('name', t('location.name'), { required: true })}</div>
        </div>
        {text('addressLine1', t('locationForm.addressLine1'), { autoComplete: 'address-line1' })}
        {text('addressLine2', t('locationForm.addressLine2'), { autoComplete: 'address-line2' })}
        <div className="grid gap-4 sm:grid-cols-[8rem_1fr_6rem]">
          {text('postalCode', t('locationForm.postalCode'), { autoComplete: 'postal-code' })}
          {text('city', t('location.city'), { autoComplete: 'address-level2' })}
          <Field label={t('location.country')} hint={countryName ?? t('locationForm.countryHint')} error={errors.countryCode}>
            {(props) => (
              <Input {...props} autoComplete="country" maxLength={2} value={values.countryCode} onChange={(event) => setValues((current) => ({ ...current, countryCode: event.target.value.toUpperCase() }))} />
            )}
          </Field>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? t('catalog.saving') : t(editing ? 'catalog.save' : 'locationForm.create')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
