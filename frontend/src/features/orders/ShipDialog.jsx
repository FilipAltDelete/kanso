import { useEffect, useId, useRef, useState } from 'react';
import { useCreateShipment } from '../../api/orders.js';
import { Button, ErrorNotice, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';

/** Offered as suggestions; any carrier can be typed (integrations come in Phase 2). */
const CARRIERS = ['PostNord', 'DHL', 'Bring', 'Budbee', 'DB Schenker', 'UPS', 'Instabox'];

/**
 * Recording a shipment: which lines, how many of each (all that is left, to
 * start with), and the carrier and tracking number. A native <dialog>: the
 * browser traps focus, Escape closes it, and focus returns to the button.
 *
 * The request carries the order's version. When someone else changed the
 * order first, the page refetches it and the dialog stays open with what is
 * now left, so the operator can check and ship again.
 */
export function ShipDialog({ order, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const titleId = useId();
  const carrierId = useId();
  const trackingId = useId();
  const carriersId = useId();
  const ship = useCreateShipment(order.id);

  const open = order.lines.filter((line) => line.quantity - line.shippedQuantity - line.cancelledQuantity > 0);
  const [quantities, setQuantities] = useState(() => Object.fromEntries(open.map((line) => [line.id, String(line.quantity - line.shippedQuantity - line.cancelledQuantity)])));
  const [carrier, setCarrier] = useState('');
  const [tracking, setTracking] = useState('');
  // When the parcel left, in the operator's local time; now, unless it was handed over earlier.
  const [shippedAt, setShippedAt] = useState(() => localDateTime(new Date()));
  const shippedAtId = useId();
  const [touched, setTouched] = useState(false);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  const number = new Intl.NumberFormat(locale);
  const rows = open.map((line) => {
    const left = line.quantity - line.shippedQuantity - line.cancelledQuantity;
    const text = quantities[line.id] ?? '0';
    const value = /^\d+$/.test(text.trim()) ? Number(text.trim()) : null;
    const problem = value === null ? t('ship.errorWholeNumber') : value > left ? t('ship.errorTooMany', { left: number.format(left) }) : null;

    return { line, left, text, value, problem };
  });
  const units = rows.reduce((sum, row) => sum + (row.problem ? 0 : row.value), 0);
  const nothing = rows.every((row) => row.problem === null) && units === 0;
  const valid = rows.every((row) => row.problem === null) && units > 0;
  const stale = ship.error?.status === 409 && ship.error.violations?.[0]?.code === 'stale_version';

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (!valid || ship.isPending) return;

    ship.mutate(
      {
        version: order.version,
        lines: rows.filter((row) => row.value > 0).map((row) => ({ lineId: row.line.id, quantity: row.value })),
        ...(carrier.trim() ? { carrier: carrier.trim() } : {}),
        ...(tracking.trim() ? { trackingNumber: tracking.trim() } : {}),
        ...(shippedAt ? { shippedAt: new Date(shippedAt).toISOString() } : {}),
      },
      { onSuccess: () => dialogRef.current?.close() },
    );
  }

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      onClose={onClose}
      className="m-auto w-full max-w-2xl rounded-lg border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/40"
    >
      <form onSubmit={submit} noValidate className="space-y-4 p-5">
        <div>
          <h2 id={titleId} className="text-lg font-semibold">
            {t('ship.title', { number: order.number })}
          </h2>
          <p className="text-sm text-slate-500">{t('ship.subtitle', { location: order.location ? `${order.location.code} · ${order.location.name}` : '' })}</p>
        </div>

        {stale ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('ship.changedElsewhere')}
          </p>
        ) : ship.error ? (
          <ErrorNotice error={ship.error} />
        ) : null}

        <table className="w-full text-sm">
          <caption className="sr-only">{t('ship.lines')}</caption>
          <thead>
            <tr className="border-b border-slate-200 text-left text-slate-600">
              <th scope="col" className="py-2 pr-2 font-medium">{t('order.sku')}</th>
              <th scope="col" className="py-2 pr-2 font-medium">{t('order.productName')}</th>
              <th scope="col" className="py-2 pr-2 text-right font-medium">{t('ship.left')}</th>
              <th scope="col" className="w-32 py-2 text-right font-medium">{t('ship.quantity')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(({ line, left, text, problem }) => {
              const inputId = `${titleId}-${line.id}`;
              const shown = touched && problem;

              return (
                <tr key={line.id} className="border-b border-slate-100 align-top">
                  <td className="py-2 pr-2 font-mono text-xs">{line.sku}</td>
                  <td className="py-2 pr-2">{line.name}</td>
                  <td className="py-2 pr-2 text-right tabular-nums">{number.format(left)}</td>
                  <td className="py-2 text-right">
                    <label htmlFor={inputId} className="sr-only">
                      {t('ship.quantityFor', { sku: line.sku })}
                    </label>
                    <Input
                      id={inputId}
                      inputMode="numeric"
                      className="text-right"
                      value={text}
                      onChange={(event) => setQuantities({ ...quantities, [line.id]: event.target.value })}
                      aria-invalid={shown ? true : undefined}
                      aria-describedby={shown ? `${inputId}-error` : undefined}
                    />
                    {shown ? (
                      <p id={`${inputId}-error`} className="mt-1 text-xs text-red-700">
                        {problem}
                      </p>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {touched && nothing ? (
          <p role="alert" className="text-sm text-red-700">
            {t('ship.errorNothing')}
          </p>
        ) : null}

        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <label htmlFor={shippedAtId} className="mb-1 block text-sm font-medium">
              {t('ship.shippedAt')}
            </label>
            <Input id={shippedAtId} type="datetime-local" value={shippedAt} max={localDateTime(new Date())} onChange={(event) => setShippedAt(event.target.value)} />
          </div>
          <div>
            <label htmlFor={carrierId} className="mb-1 block text-sm font-medium">
              {t('ship.carrier')}
            </label>
            <Input id={carrierId} list={carriersId} autoComplete="off" maxLength={64} value={carrier} onChange={(event) => setCarrier(event.target.value)} />
            <datalist id={carriersId}>
              {CARRIERS.map((name) => (
                <option key={name} value={name} />
              ))}
            </datalist>
          </div>
          <div>
            <label htmlFor={trackingId} className="mb-1 block text-sm font-medium">
              {t('ship.trackingNumber')}
            </label>
            <Input id={trackingId} autoComplete="off" maxLength={128} className="font-mono" value={tracking} onChange={(event) => setTracking(event.target.value)} />
          </div>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={ship.isPending}>
            {ship.isPending ? t('ship.saving') : t('ship.submit', { units: number.format(units) })}
          </Button>
        </div>
      </form>
    </dialog>
  );
}

/** A Date as the value of a datetime-local input, in local time: 2026-09-28T14:05. */
function localDateTime(date) {
  const pad = (value) => String(value).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
