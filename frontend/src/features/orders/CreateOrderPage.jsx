import { useEffect, useId, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { customers } from '../../api/customers.js';
import { useAllLocations } from '../../api/inventory.js';
import { useChannels, useCreateOrder } from '../../api/orders.js';
import { Button, Card, Checkbox, ErrorNotice, Input, Select } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatMoney, parseMoney } from '../../lib/money.js';

const EMPTY_ADDRESS = { name: '', line1: '', line2: '', postalCode: '', city: '', region: '', countryCode: 'SE', phone: '' };
const emptyLine = () => ({ key: crypto.randomUUID(), sku: '', name: '', quantity: '1', unitPrice: '' });

/** Blank strings are left out, so optional fields reach the API as absent. */
function compact(values) {
  return Object.fromEntries(Object.entries(values).filter(([, value]) => value.trim() !== '').map(([key, value]) => [key, value.trim()]));
}

/**
 * Creating an order by hand (a phone order, a replacement). Prices are typed
 * in the locale's format and sent as integer minor units; the server checks
 * everything again and its violations are shown at the fields they name.
 */
export function CreateOrderPage() {
  const { t, locale } = useI18n();
  const navigate = useNavigate();
  const channels = useChannels();
  const locations = useAllLocations();
  const create = useCreateOrder();

  const [channel, setChannel] = useState('manual');
  // Empty: the installation's default location decides.
  const [location, setLocation] = useState('');
  const [currency, setCurrency] = useState('');
  const [customer, setCustomer] = useState({ name: '', email: '' });
  const [shipping, setShipping] = useState(EMPTY_ADDRESS);

  // Opened from a customer's page (`?customer=<id>`): the order is linked to
  // that record, and starts from its name, email and default shipping address.
  const { customer: customerId } = useSearch({ strict: false });
  const linked = useQuery({
    queryKey: ['customer', customerId],
    queryFn: ({ signal }) => customers.get(customerId, signal),
    enabled: typeof customerId === 'string' && customerId !== '',
  });
  const [prefilledFrom, setPrefilledFrom] = useState(null);
  useEffect(() => {
    const record = linked.data;
    if (!record || prefilledFrom === record.id) return;
    setPrefilledFrom(record.id);
    setCustomer({ name: record.name, email: record.email });
    const address = record.addresses.find((candidate) => candidate.type === 'shipping' && candidate.isDefault) ?? record.addresses.find((candidate) => candidate.type === 'shipping');
    if (address) {
      setShipping(Object.fromEntries(Object.keys(EMPTY_ADDRESS).map((key) => [key, address[key] ?? ''])));
    }
  }, [linked.data, prefilledFrom]);
  const [billingSame, setBillingSame] = useState(true);
  const [billing, setBilling] = useState(EMPTY_ADDRESS);
  const [lines, setLines] = useState(() => [emptyLine()]);
  const [localErrors, setLocalErrors] = useState({});

  const channelCurrency = channels.data?.find((candidate) => candidate.code === channel)?.currency ?? 'SEK';
  const orderCurrency = /^[A-Z]{3}$/.test(currency) ? currency : channelCurrency;

  // The server's messages are English; a known code is shown in the UI's language instead.
  const serverErrors = Object.fromEntries(
    (create.error?.violations ?? []).map((violation) => {
      const translated = t(`violation.${violation.code}`);

      return [violation.path, translated === `violation.${violation.code}` ? violation.message : translated];
    }),
  );
  const errors = { ...serverErrors, ...localErrors };

  const updateLine = (key, patch) => setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));

  let total = 0;
  for (const line of lines) {
    const price = parseMoney(line.unitPrice, orderCurrency, locale);
    const quantity = Number(line.quantity);
    if (price !== null && Number.isInteger(quantity)) total += price * quantity;
  }

  function submit(event) {
    event.preventDefault();

    const problems = {};
    const body = {
      channel,
      ...(currency ? { currency } : {}),
      ...(location ? { location } : {}),
      customer: { ...compact(customer), ...(linked.data ? { id: linked.data.id } : {}) },
      shippingAddress: { ...compact(shipping), countryCode: shipping.countryCode.trim().toUpperCase() },
      ...(billingSame ? {} : { billingAddress: { ...compact(billing), countryCode: billing.countryCode.trim().toUpperCase() } }),
      lines: lines.map((line, index) => {
        const unitPrice = parseMoney(line.unitPrice, orderCurrency, locale);
        if (unitPrice === null) problems[`lines[${index}].unitPrice`] = t('orderForm.priceInvalid');
        const quantity = Number(line.quantity);

        // A blank name is left out: the product's own name is used.
        return { sku: line.sku.trim(), ...(line.name.trim() ? { name: line.name.trim() } : {}), quantity: Number.isInteger(quantity) ? quantity : line.quantity, unitPrice };
      }),
    };

    setLocalErrors(problems);
    if (Object.keys(problems).length > 0) return;

    create.mutate(body, { onSuccess: (order) => navigate({ to: '/orders/$orderId', params: { orderId: order.id } }) });
  }

  return (
    <form onSubmit={submit} noValidate className="max-w-5xl space-y-6">
      <div className="space-y-2">
        <Link to="/orders" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {t('orders.back')}
        </Link>
        <h1 className="text-xl font-semibold">{t('orders.new')}</h1>
      </div>

      {create.error ? <ErrorNotice error={create.error.status === 422 ? { message: t('orderForm.fixErrors') } : create.error} /> : null}

      <Card className="grid gap-4 p-4 sm:grid-cols-2">
        <Field label={t('order.channel')} error={errors.channel}>
          {(props) => (
            <Select {...props} value={channel} onChange={(event) => setChannel(event.target.value)}>
              {(channels.data ?? [{ code: 'manual', name: 'Manual' }]).map((option) => (
                <option key={option.code} value={option.code}>
                  {option.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('orderForm.location')} error={errors.location}>
          {(props) => (
            <Select {...props} value={location} onChange={(event) => setLocation(event.target.value)}>
              <option value="">{t('orderForm.defaultLocation')}</option>
              {(locations.data ?? []).map((option) => (
                <option key={option.id} value={option.code}>
                  {option.code} · {option.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('order.currency')} hint={t('orderForm.currencyHint', { currency: channelCurrency })} error={errors.currency}>
          {(props) => (
            <Input {...props} value={currency} maxLength={3} placeholder={channelCurrency} onChange={(event) => setCurrency(event.target.value.toUpperCase())} />
          )}
        </Field>
      </Card>

      <Card className="space-y-4 p-4">
        <h2 className="text-sm font-semibold">{t('order.customer')}</h2>
        {linked.data ? (
          <p className="text-sm text-slate-600">
            {t('orderForm.linkedCustomer', { name: linked.data.name, email: linked.data.email })}
          </p>
        ) : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('orderForm.customerName')} required error={errors['customer.name']}>
            {(props) => <Input {...props} autoComplete="off" value={customer.name} onChange={(event) => setCustomer({ ...customer, name: event.target.value })} />}
          </Field>
          <Field label={t('orderForm.email')} error={errors['customer.email']}>
            {(props) => <Input {...props} type="email" autoComplete="off" value={customer.email} onChange={(event) => setCustomer({ ...customer, email: event.target.value })} />}
          </Field>
        </div>
      </Card>

      <AddressFields legend={t('order.shippingAddress')} path="shippingAddress" value={shipping} onChange={setShipping} errors={errors} />

      <div className="flex items-center gap-2">
        <Checkbox id="billing-same" checked={billingSame} onChange={(event) => setBillingSame(event.target.checked)} />
        <label htmlFor="billing-same" className="text-sm">
          {t('orderForm.billingSame')}
        </label>
      </div>
      {billingSame ? null : <AddressFields legend={t('order.billingAddress')} path="billingAddress" value={billing} onChange={setBilling} errors={errors} />}

      <Card className="space-y-3 p-4">
        <h2 className="text-sm font-semibold">{t('order.lines')}</h2>
        {errors.lines ? (
          <p role="alert" className="text-sm text-red-700">
            {errors.lines}
          </p>
        ) : null}
        <ol className="space-y-3">
          {lines.map((line, index) => {
            const path = `lines[${index}]`;
            const price = parseMoney(line.unitPrice, orderCurrency, locale);
            const quantity = Number(line.quantity);

            return (
              <li key={line.key} aria-label={t('orderForm.lineN', { n: index + 1 })} className="grid items-start gap-2 sm:grid-cols-[8rem_1fr_6rem_8rem_8rem_auto]">
                <Field label={t('order.sku')} required error={errors[`${path}.sku`]}>
                  {(props) => <Input {...props} className="font-mono" value={line.sku} onChange={(event) => updateLine(line.key, { sku: event.target.value })} />}
                </Field>
                <Field label={t('order.productName')} error={errors[`${path}.name`]}>
                  {(props) => <Input {...props} value={line.name} placeholder={t('orderForm.nameHint')} onChange={(event) => updateLine(line.key, { name: event.target.value })} />}
                </Field>
                <Field label={t('order.quantity')} required error={errors[`${path}.quantity`]}>
                  {(props) => <Input {...props} type="number" min={1} step={1} value={line.quantity} onChange={(event) => updateLine(line.key, { quantity: event.target.value })} />}
                </Field>
                <Field label={t('order.unitPrice')} required error={errors[`${path}.unitPrice`]}>
                  {(props) => <Input {...props} inputMode="decimal" className="text-right" value={line.unitPrice} onChange={(event) => updateLine(line.key, { unitPrice: event.target.value })} />}
                </Field>
                <div className="flex flex-col gap-1">
                  <span className="text-xs font-medium text-slate-600">{t('order.lineTotal')}</span>
                  <output className="h-9 py-2 text-right text-sm tabular-nums">
                    {price !== null && Number.isInteger(quantity) ? formatMoney(price * quantity, orderCurrency, locale) : '—'}
                  </output>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="self-end"
                  aria-label={t('orderForm.removeLine', { n: index + 1 })}
                  disabled={lines.length === 1}
                  onClick={() => setLines((current) => current.filter((candidate) => candidate.key !== line.key))}
                >
                  <Trash2 className="size-4" aria-hidden="true" />
                </Button>
              </li>
            );
          })}
        </ol>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3">
          <Button type="button" variant="outline" size="sm" onClick={() => setLines((current) => [...current, emptyLine()])}>
            <Plus className="size-3.5" aria-hidden="true" />
            {t('orderForm.addLine')}
          </Button>
          <p className="text-sm">
            {t('order.total')}: <span className="font-semibold tabular-nums">{formatMoney(total, orderCurrency, locale)}</span>
          </p>
        </div>
      </Card>

      <div className="flex gap-2">
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? t('orderForm.creating') : t('orderForm.create')}
        </Button>
        <Link to="/orders" className="inline-flex h-9 items-center rounded-md px-4 text-sm font-medium text-slate-700 hover:bg-slate-100">
          {t('orderForm.cancel')}
        </Link>
      </div>
    </form>
  );
}

function AddressFields({ legend, path, value, onChange, errors }) {
  const { t } = useI18n();
  const field = (name, props = {}) => (
    <Field label={t(`address.${name}`)} required={props.required} error={errors[`${path}.${name}`]}>
      {(inputProps) => <Input {...inputProps} value={value[name]} maxLength={props.maxLength} onChange={(event) => onChange({ ...value, [name]: event.target.value })} />}
    </Field>
  );

  return (
    <Card className="p-4">
      <fieldset className="space-y-4">
        <legend className="text-sm font-semibold">{legend}</legend>
        <div className="grid gap-4 sm:grid-cols-2">
          {field('name')}
          {field('phone')}
          {field('line1', { required: true })}
          {field('line2')}
          {field('postalCode', { required: true })}
          {field('city', { required: true })}
          {field('region')}
          {field('countryCode', { required: true, maxLength: 2 })}
        </div>
      </fieldset>
    </Card>
  );
}

/** A labelled control with its hint and error wired up for screen readers. */
function Field({ label, hint, error, required = false, children }) {
  const { t } = useI18n();
  const id = useId();
  const described = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-600">
        {label}
        {required ? <span className="sr-only"> ({t('orderForm.required')})</span> : null}
        {required ? <span aria-hidden="true"> *</span> : null}
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': described, 'aria-required': required || undefined })}
      {hint ? (
        <p id={`${id}-hint`} className="text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-xs text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}
