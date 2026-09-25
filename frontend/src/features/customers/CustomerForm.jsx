import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Button, Card, Checkbox, Input, Select } from '../../components/ui/primitives.jsx';
import { countryOptions } from '../../lib/countries.js';
import { useI18n } from '../../lib/i18n.jsx';

const ADDRESS_FIELDS = ['name', 'company', 'line1', 'line2', 'postalCode', 'city', 'region', 'phone'];
const REQUIRED_ADDRESS_FIELDS = ['line1', 'postalCode', 'city'];

let nextKey = 0;

function blankAddress(type) {
  nextKey += 1;

  return { key: `new-${nextKey}`, type, isDefault: false, countryCode: 'SE', ...Object.fromEntries(ADDRESS_FIELDS.map((field) => [field, ''])) };
}

/** The form's state for a customer, or for a new one. */
export function toFormState(customer) {
  return {
    email: customer?.email ?? '',
    name: customer?.name ?? '',
    phone: customer?.phone ?? '',
    addresses: (customer?.addresses ?? []).map((address) => ({
      ...address,
      key: address.id,
      ...Object.fromEntries(ADDRESS_FIELDS.map((field) => [field, address[field] ?? ''])),
    })),
  };
}

/** What the API is sent: blank optional fields as null, addresses in the order shown. */
export function toPayload(state) {
  const orNull = (value) => (value.trim() === '' ? null : value.trim());

  return {
    email: state.email.trim(),
    name: state.name.trim(),
    phone: orNull(state.phone),
    addresses: state.addresses.map(({ key: _key, id, type, isDefault, countryCode, ...fields }) => ({
      ...(id ? { id } : {}),
      type,
      isDefault,
      countryCode,
      ...Object.fromEntries(ADDRESS_FIELDS.map((field) => [field, orNull(fields[field] ?? '')])),
    })),
  };
}

/** A violation in the UI's language when its code is known, else as the API worded it. */
function violationText(violation, t) {
  const key = `violation.${violation.code}`;
  const text = t(key);

  return text === key ? violation.message : text;
}

function Field({ label, error, required, children }) {
  const id = useId();
  const errorId = `${id}-error`;

  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {label}
        {required ? <span aria-hidden="true"> *</span> : null}
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? errorId : undefined, 'aria-required': required || undefined })}
      {error ? (
        <p id={errorId} className="text-xs text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Create or edit a customer. Validation is the API's: its violations are
 * shown next to the fields they name, and anything that names no field in
 * the summary at the top.
 */
export function CustomerForm({ initial, submitLabel, submitting, error, onSubmit, onCancel }) {
  const { t, locale } = useI18n();
  const [state, setState] = useState(() => toFormState(initial));
  const summaryRef = useRef(null);
  const countries = useMemo(() => countryOptions(locale), [locale]);

  const fieldErrors = useMemo(() => {
    const errors = {};
    for (const violation of error?.violations ?? []) errors[violation.path] ??= violationText(violation, t);

    return errors;
  }, [error, t]);

  const knownPaths = new Set(['email', 'name', 'phone']);
  state.addresses.forEach((_, index) => {
    for (const field of ['type', 'isDefault', 'countryCode', ...ADDRESS_FIELDS]) knownPaths.add(`addresses[${index}].${field}`);
  });
  const unplaced = (error?.violations ?? []).filter((violation) => !knownPaths.has(violation.path));

  useEffect(() => {
    if (error) summaryRef.current?.focus();
  }, [error]);

  const set = (field) => (event) => setState((current) => ({ ...current, [field]: event.target.value }));

  function setAddress(index, patch) {
    setState((current) => ({
      ...current,
      addresses: current.addresses.map((address, i) => {
        if (i === index) return { ...address, ...patch };
        // One default per type: marking one clears the others of its type.
        if (patch.isDefault && address.type === (patch.type ?? current.addresses[index].type)) return { ...address, isDefault: false };

        return address;
      }),
    }));
  }

  function addAddress(type) {
    setState((current) => ({ ...current, addresses: [...current.addresses, blankAddress(type)] }));
  }

  function removeAddress(index) {
    setState((current) => ({ ...current, addresses: current.addresses.filter((_, i) => i !== index) }));
  }

  function handleSubmit(event) {
    event.preventDefault();
    onSubmit(toPayload(state));
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-6">
      {error ? (
        <div ref={summaryRef} tabIndex={-1} role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          <p className="font-medium">{error.status === 422 ? t('form.fixErrors') : error.message}</p>
          {unplaced.length ? (
            <ul className="mt-1 list-inside list-disc">
              {unplaced.map((violation) => (
                <li key={`${violation.path}-${violation.code}`}>{violationText(violation, t)}</li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      <Card className="space-y-4 p-4">
        <h2 className="font-medium">{t('customer.details')}</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('customer.name')} error={fieldErrors.name} required>
            {(props) => <Input {...props} value={state.name} onChange={set('name')} autoComplete="off" />}
          </Field>
          <Field label={t('customer.email')} error={fieldErrors.email} required>
            {(props) => <Input {...props} type="email" value={state.email} onChange={set('email')} autoComplete="off" />}
          </Field>
          <Field label={t('customer.phone')} error={fieldErrors.phone}>
            {(props) => <Input {...props} type="tel" value={state.phone} onChange={set('phone')} autoComplete="off" />}
          </Field>
        </div>
      </Card>

      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-medium">{t('customer.addresses')}</h2>
          <div className="flex gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => addAddress('shipping')}>
              <Plus className="size-4" aria-hidden="true" />
              {t('customer.address.addShipping')}
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => addAddress('billing')}>
              <Plus className="size-4" aria-hidden="true" />
              {t('customer.address.addBilling')}
            </Button>
          </div>
        </div>

        {state.addresses.length === 0 ? <p className="text-sm text-slate-500">{t('customer.addresses.none')}</p> : null}

        {state.addresses.map((address, index) => {
          const path = (field) => `addresses[${index}].${field}`;
          const legend = t('customer.address.legend', { type: t(`customer.address.type.${address.type}`), number: index + 1 });

          return (
            <fieldset key={address.key} className="rounded-lg border border-slate-200 bg-white p-4">
              <legend className="px-1 text-sm font-medium">{legend}</legend>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('customer.address.type')} error={fieldErrors[path('type')]}>
                  {(props) => (
                    <Select {...props} value={address.type} onChange={(event) => setAddress(index, { type: event.target.value, isDefault: false })}>
                      <option value="shipping">{t('customer.address.type.shipping')}</option>
                      <option value="billing">{t('customer.address.type.billing')}</option>
                    </Select>
                  )}
                </Field>
                <div className="flex items-end gap-2 pb-2">
                  <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={address.isDefault} onChange={(event) => setAddress(index, { isDefault: event.target.checked })} />
                    {t('customer.address.makeDefault')}
                  </label>
                </div>
                {ADDRESS_FIELDS.map((field) => (
                  <Field
                    key={field}
                    label={t(`customer.address.${field}`)}
                    error={fieldErrors[path(field)]}
                    required={REQUIRED_ADDRESS_FIELDS.includes(field)}
                  >
                    {(props) => <Input {...props} value={address[field]} onChange={(event) => setAddress(index, { [field]: event.target.value })} />}
                  </Field>
                ))}
                <Field label={t('customer.address.country')} error={fieldErrors[path('countryCode')]} required>
                  {(props) => (
                    <Select {...props} className="w-full" value={address.countryCode} onChange={(event) => setAddress(index, { countryCode: event.target.value })}>
                      {countries.map((country) => (
                        <option key={country.value} value={country.value}>
                          {country.label}
                        </option>
                      ))}
                    </Select>
                  )}
                </Field>
              </div>
              <div className="mt-3 flex justify-end">
                <Button type="button" variant="ghost" size="sm" onClick={() => removeAddress(index)}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  {t('customer.address.remove', { address: legend })}
                </Button>
              </div>
            </fieldset>
          );
        })}
      </div>

      <div className="flex gap-2">
        <Button type="submit" disabled={submitting}>
          {submitting ? t('form.saving') : submitLabel}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          {t('form.cancel')}
        </Button>
      </div>
    </form>
  );
}
