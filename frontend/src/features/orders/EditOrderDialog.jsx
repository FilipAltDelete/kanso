import { useRef, useState } from 'react';
import { Plus, Trash2, Undo2 } from 'lucide-react';
import { useEditOrder } from '../../api/orders.js';
import { Button, Checkbox, Dialog, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatMoney, parseMoney } from '../../lib/money.js';
import { AddressFields, compact, EMPTY_ADDRESS } from './CreateOrderPage.jsx';

const newLine = () => ({ key: crypto.randomUUID(), sku: '', name: '', quantity: '1', unitPrice: '' });

/** An address from the API (nulls left out) as the form's strings. */
function addressForm(address) {
  return Object.fromEntries(Object.keys(EMPTY_ADDRESS).map((key) => [key, address?.[key] ?? '']));
}

/** The form's strings as the API takes them: blanks left out, the country code upper case. */
function addressBody(form) {
  return { ...compact(form), countryCode: form.countryCode.trim().toUpperCase() };
}

/** Whether two addresses say the same, ignoring fields that are null or blank. */
function sameAddress(a, b) {
  const fields = (address) => JSON.stringify(Object.keys(EMPTY_ADDRESS).map((key) => address?.[key] || null));

  return fields(a) === fields(b);
}

/**
 * Editing an order before fulfillment: line quantities, lines removed and
 * added, the customer's name and email, the addresses. Only what changed is
 * sent, with the order's version; the server moves the reservation with the
 * lines and answers 409 when a product is short or someone changed the order
 * first. A line keeps its SKU and price: to change them, remove it and add a
 * new one.
 */
export function EditOrderDialog({ order, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const edit = useEditOrder(order.id);
  const money = (minor) => formatMoney(minor, order.currency, locale);

  const [customer, setCustomer] = useState({ name: order.customer.name, email: order.customer.email ?? '' });
  const [shipping, setShipping] = useState(() => addressForm(order.shippingAddress));
  const [billingSame, setBillingSame] = useState(!order.billingAddress);
  const [billing, setBilling] = useState(() => addressForm(order.billingAddress ?? order.shippingAddress));
  const [quantities, setQuantities] = useState(() => Object.fromEntries(order.lines.map((line) => [line.id, String(line.quantity)])));
  const [removed, setRemoved] = useState(() => new Set());
  const [added, setAdded] = useState([]);
  const [localErrors, setLocalErrors] = useState({});
  const [noChanges, setNoChanges] = useState(false);
  // Which row each line of the last request was, so the server's `lines[i]` errors land on it.
  const [sentRows, setSentRows] = useState([]);

  const updateAdded = (key, patch) => setAdded((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  const toggleRemoved = (id) =>
    setRemoved((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);

      return next;
    });

  // The server's messages are English; a known code is shown in the UI's language instead.
  const serverErrors = {};
  for (const violation of edit.error?.status === 422 ? (edit.error.violations ?? []) : []) {
    const translated = t(`violation.${violation.code}`);
    const message = translated === `violation.${violation.code}` ? violation.message : translated;
    const match = /^lines\[(\d+)\](?:\.(\w+))?$/.exec(violation.path);
    const row = match ? sentRows[Number(match[1])] : null;
    serverErrors[row ? `${row}.${match[2] ?? 'quantity'}` : violation.path] = message;
  }
  const errors = { ...serverErrors, ...localErrors };
  const code = edit.error?.violations?.[0]?.code;
  const shortSkus =
    edit.error?.status === 409
      ? (edit.error.violations ?? [])
          .filter((violation) => violation.code === 'insufficient_stock')
          .map((violation) => {
            const row = sentRows[Number(/^lines\[(\d+)\]/.exec(violation.path)?.[1])];

            return order.lines.find((line) => line.id === row)?.sku ?? added.find((line) => line.key === row)?.sku;
          })
          .filter(Boolean)
      : [];

  // The total as it would be: the units not cancelled of each kept line, and the new lines.
  let total = 0;
  for (const line of order.lines) {
    const quantity = Number(quantities[line.id]);
    if (!removed.has(line.id) && Number.isInteger(quantity)) total += line.unitPrice * Math.max(0, quantity - line.cancelledQuantity);
  }
  for (const line of added) {
    const price = parseMoney(line.unitPrice, order.currency, locale);
    const quantity = Number(line.quantity);
    if (price !== null && Number.isInteger(quantity)) total += price * quantity;
  }

  function submit(event) {
    event.preventDefault();
    setNoChanges(false);

    const problems = {};
    const lines = [];
    const rows = [];
    for (const line of order.lines) {
      if (removed.has(line.id)) {
        lines.push({ lineId: line.id, quantity: 0 });
        rows.push(line.id);
        continue;
      }
      const text = String(quantities[line.id]).trim();
      const quantity = /^\d+$/.test(text) ? Number(text) : null;
      const least = Math.max(1, line.shippedQuantity + line.cancelledQuantity);
      if (quantity === null || quantity < least) {
        problems[`${line.id}.quantity`] = t('orderEdit.errorAtLeast', { least });
      } else if (quantity !== line.quantity) {
        lines.push({ lineId: line.id, quantity });
        rows.push(line.id);
      }
    }
    for (const line of added) {
      const unitPrice = parseMoney(line.unitPrice, order.currency, locale);
      const quantity = /^\d+$/.test(line.quantity.trim()) ? Number(line.quantity.trim()) : null;
      if (!line.sku.trim()) problems[`${line.key}.sku`] = t('violation.required');
      if (quantity === null || quantity < 1) problems[`${line.key}.quantity`] = t('orderEdit.errorAtLeast', { least: 1 });
      if (unitPrice === null) problems[`${line.key}.unitPrice`] = t('orderForm.priceInvalid');
      lines.push({ sku: line.sku.trim(), ...(line.name.trim() ? { name: line.name.trim() } : {}), quantity, unitPrice });
      rows.push(line.key);
    }

    const body = { version: order.version };
    if (lines.length > 0) body.lines = lines;

    const name = customer.name.trim();
    const email = customer.email.trim();
    const customerChange = {
      ...(name !== order.customer.name ? { name } : {}),
      ...(email !== (order.customer.email ?? '') ? { email: email || null } : {}),
    };
    if (Object.keys(customerChange).length > 0) body.customer = customerChange;

    if (!sameAddress(addressBody(shipping), order.shippingAddress)) body.shippingAddress = addressBody(shipping);
    if (billingSame) {
      if (order.billingAddress) body.billingAddress = null;
    } else if (!order.billingAddress || !sameAddress(addressBody(billing), order.billingAddress)) {
      body.billingAddress = addressBody(billing);
    }

    setLocalErrors(problems);
    if (Object.keys(problems).length > 0) return;
    if (Object.keys(body).length === 1) {
      setNoChanges(true);
      return;
    }

    setSentRows(rows);
    edit.mutate(body, { onSuccess: () => dialogRef.current?.close() });
  }

  return (
    <Dialog dialogRef={dialogRef} title={t('orderEdit.title', { number: order.number })} description={t('orderEdit.subtitle')} onClose={onClose} className="max-h-[90vh] max-w-4xl overflow-y-auto">
      <form onSubmit={submit} noValidate className="space-y-4">
        {code === 'stale_version' ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('orderEdit.changedElsewhere')}
          </p>
        ) : shortSkus.length > 0 ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('orderEdit.insufficientStock', { location: order.location?.code ?? '', skus: shortSkus.join(', ') })}
          </p>
        ) : code === 'not_editable' ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('orderEdit.notEditable')}
          </p>
        ) : edit.error?.status === 422 ? (
          <ErrorNotice error={{ message: errors.lines ?? t('orderEdit.fixErrors') }} />
        ) : edit.error ? (
          <ErrorNotice error={edit.error} />
        ) : null}

        <fieldset className="space-y-3">
          <legend className="text-sm font-semibold">{t('order.lines')}</legend>
          <ul className="space-y-2">
            {order.lines.map((line) => {
              const gone = removed.has(line.id);

              return (
                <li key={line.id} className="grid items-start gap-2 sm:grid-cols-[1fr_7rem_8rem_auto]">
                  <div className={gone ? 'text-slate-400 line-through' : undefined}>
                    <p className="font-mono text-xs">{line.sku}</p>
                    <p className="text-sm">{line.name}</p>
                    <p className="text-xs text-slate-500">
                      {money(line.unitPrice)}
                      {line.cancelledQuantity > 0 ? <> · {t('orderEdit.cancelledUnits', { count: line.cancelledQuantity })}</> : null}
                    </p>
                  </div>
                  <Field label={t('orderEdit.quantityFor', { sku: line.sku })} error={errors[`${line.id}.quantity`]}>
                    {(props) => (
                      <Input
                        {...props}
                        type="number"
                        min={Math.max(1, line.shippedQuantity + line.cancelledQuantity)}
                        step={1}
                        disabled={gone}
                        value={quantities[line.id]}
                        onChange={(event) => setQuantities({ ...quantities, [line.id]: event.target.value })}
                      />
                    )}
                  </Field>
                  <p className="self-end py-2 text-right text-sm tabular-nums">{gone ? '—' : money(line.unitPrice * Math.max(0, (Number(quantities[line.id]) || 0) - line.cancelledQuantity))}</p>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="self-end"
                    aria-label={t(gone ? 'orderEdit.keepLine' : 'orderEdit.removeLine', { sku: line.sku })}
                    aria-pressed={gone}
                    onClick={() => toggleRemoved(line.id)}
                  >
                    {gone ? <Undo2 className="size-4" aria-hidden="true" /> : <Trash2 className="size-4" aria-hidden="true" />}
                  </Button>
                </li>
              );
            })}
            {added.map((line, index) => (
              <li key={line.key} aria-label={t('orderEdit.newLineN', { n: index + 1 })} className="grid items-start gap-2 sm:grid-cols-[8rem_1fr_6rem_8rem_auto]">
                <Field label={t('order.sku')} error={errors[`${line.key}.sku`]}>
                  {(props) => <Input {...props} className="font-mono" value={line.sku} onChange={(event) => updateAdded(line.key, { sku: event.target.value })} />}
                </Field>
                <Field label={t('order.productName')} error={errors[`${line.key}.name`]}>
                  {(props) => <Input {...props} value={line.name} placeholder={t('orderForm.nameHint')} onChange={(event) => updateAdded(line.key, { name: event.target.value })} />}
                </Field>
                <Field label={t('order.quantity')} error={errors[`${line.key}.quantity`]}>
                  {(props) => <Input {...props} type="number" min={1} step={1} value={line.quantity} onChange={(event) => updateAdded(line.key, { quantity: event.target.value })} />}
                </Field>
                <Field label={t('order.unitPrice')} error={errors[`${line.key}.unitPrice`]}>
                  {(props) => <Input {...props} inputMode="decimal" className="text-right" value={line.unitPrice} onChange={(event) => updateAdded(line.key, { unitPrice: event.target.value })} />}
                </Field>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="self-end"
                  aria-label={t('orderEdit.dropNewLine', { n: index + 1 })}
                  onClick={() => setAdded((current) => current.filter((candidate) => candidate.key !== line.key))}
                >
                  <Trash2 className="size-4" aria-hidden="true" />
                </Button>
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-3">
            <Button type="button" variant="outline" size="sm" onClick={() => setAdded((current) => [...current, newLine()])}>
              <Plus className="size-3.5" aria-hidden="true" />
              {t('orderForm.addLine')}
            </Button>
            <p className="text-sm">
              {t('orderEdit.newTotal')}: <output className="font-semibold tabular-nums">{money(total)}</output>
            </p>
          </div>
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="text-sm font-semibold">{t('order.customer')}</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('orderForm.customerName')} error={errors['customer.name']}>
              {(props) => <Input {...props} autoComplete="off" value={customer.name} onChange={(event) => setCustomer({ ...customer, name: event.target.value })} />}
            </Field>
            <Field label={t('orderForm.email')} error={errors['customer.email']}>
              {(props) => <Input {...props} type="email" autoComplete="off" value={customer.email} onChange={(event) => setCustomer({ ...customer, email: event.target.value })} />}
            </Field>
          </div>
        </fieldset>

        <AddressFields legend={t('order.shippingAddress')} path="shippingAddress" value={shipping} onChange={setShipping} errors={errors} />
        <div className="flex items-center gap-2">
          <Checkbox id={`${order.id}-billing-same`} checked={billingSame} onChange={(event) => setBillingSame(event.target.checked)} />
          <label htmlFor={`${order.id}-billing-same`} className="text-sm">
            {t('orderForm.billingSame')}
          </label>
        </div>
        {billingSame ? null : <AddressFields legend={t('order.billingAddress')} path="billingAddress" value={billing} onChange={setBilling} errors={errors} />}

        {noChanges ? (
          <p role="alert" className="text-sm text-slate-700">
            {t('orderEdit.noChanges')}
          </p>
        ) : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={edit.isPending}>
            {edit.isPending ? t('orderEdit.saving') : t('orderEdit.save')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
