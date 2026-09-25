import { useId, useRef, useState } from 'react';
import { unitsLeft, useCancelItems } from '../../api/orders.js';
import { Button, Dialog, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';

const REASON_MAX_LENGTH = 255;

/**
 * A partial cancel: how many units of each line will not ship (none, to
 * start with), and why. Shipped units cannot be cancelled, so each line
 * offers what it has left. Cancelling everything that is left finishes the
 * order: shipped if part of it has shipped, cancelled otherwise; the dialog
 * says which before it is sent.
 *
 * The request carries the order's version; when someone changed the order
 * first, the page refetches it and the dialog says so.
 */
export function CancelItemsDialog({ order, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const tableId = useId();
  const cancel = useCancelItems(order.id);

  const open = order.lines.filter((line) => unitsLeft(line) > 0);
  const [quantities, setQuantities] = useState(() => Object.fromEntries(open.map((line) => [line.id, '0'])));
  const [reason, setReason] = useState('');
  const [touched, setTouched] = useState(false);

  const number = new Intl.NumberFormat(locale);
  const rows = open.map((line) => {
    const left = unitsLeft(line);
    const text = quantities[line.id] ?? '0';
    const value = /^\d+$/.test(text.trim()) ? Number(text.trim()) : null;
    const problem = value === null ? t('cancelItems.errorWholeNumber') : value > left ? t('cancelItems.errorTooMany', { left: number.format(left) }) : null;

    return { line, left, text, value, problem };
  });
  const valid = rows.every((row) => row.problem === null);
  const units = valid ? rows.reduce((sum, row) => sum + row.value, 0) : 0;
  const everything = valid && units > 0 && units === rows.reduce((sum, row) => sum + row.left, 0);
  const anyShipped = order.lines.some((line) => line.shippedQuantity > 0);
  const code = cancel.error?.violations?.[0]?.code;

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (!valid || units === 0 || cancel.isPending) return;

    cancel.mutate(
      {
        version: order.version,
        lines: rows.filter((row) => row.value > 0).map((row) => ({ lineId: row.line.id, quantity: row.value })),
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      },
      { onSuccess: () => dialogRef.current?.close() },
    );
  }

  return (
    <Dialog dialogRef={dialogRef} title={t('cancelItems.title', { number: order.number })} description={t('cancelItems.subtitle')} onClose={onClose} className="max-w-2xl">
      <form onSubmit={submit} noValidate className="space-y-4">
        {code === 'stale_version' ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('cancelItems.changedElsewhere')}
          </p>
        ) : code === 'release_first' ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t('cancelItems.releaseFirst')}
          </p>
        ) : cancel.error ? (
          <ErrorNotice error={cancel.error} />
        ) : null}

        <table className="w-full text-sm">
          <caption id={tableId} className="sr-only">
            {t('cancelItems.lines')}
          </caption>
          <thead>
            <tr className="border-b border-slate-200 text-left text-slate-600">
              <th scope="col" className="py-2 pr-2 font-medium">{t('order.sku')}</th>
              <th scope="col" className="py-2 pr-2 font-medium">{t('order.productName')}</th>
              <th scope="col" className="py-2 pr-2 text-right font-medium">{t('cancelItems.left')}</th>
              <th scope="col" className="w-32 py-2 text-right font-medium">{t('cancelItems.quantity')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(({ line, left, text, problem }) => {
              const inputId = `${tableId}-${line.id}`;
              const shown = touched && problem;

              return (
                <tr key={line.id} className="border-b border-slate-100 align-top">
                  <td className="py-2 pr-2 font-mono text-xs">{line.sku}</td>
                  <td className="py-2 pr-2">{line.name}</td>
                  <td className="py-2 pr-2 text-right tabular-nums">{number.format(left)}</td>
                  <td className="py-2 text-right">
                    <label htmlFor={inputId} className="sr-only">
                      {t('cancelItems.quantityFor', { sku: line.sku })}
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
        {touched && valid && units === 0 ? (
          <p role="alert" className="text-sm text-red-700">
            {t('cancelItems.errorNothing')}
          </p>
        ) : null}
        {everything ? (
          <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {t(anyShipped ? 'cancelItems.finishesShipped' : 'cancelItems.finishesCancelled')}
          </p>
        ) : null}

        <Field label={t('cancelItems.reason')} hint={t('cancelItems.reasonHint')}>
          {(props) => <Input {...props} autoComplete="off" maxLength={REASON_MAX_LENGTH} value={reason} onChange={(event) => setReason(event.target.value)} />}
        </Field>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('cancelItems.close')}
          </Button>
          <Button type="submit" variant="danger" disabled={cancel.isPending}>
            {cancel.isPending ? t('cancelItems.saving') : t('cancelItems.submit', { units: number.format(units) })}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
