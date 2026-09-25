import { useEffect, useId, useRef, useState } from 'react';
import { ADJUSTMENT_REASONS, useAdjustStock } from '../../api/inventory.js';
import { Button, ErrorNotice, Input, Select } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity, parseQuantity } from '../../lib/quantity.js';

const MODES = ['delta', 'count'];

/**
 * Adjusting one product's stock at one location. A native <dialog>: the
 * browser traps focus, Escape closes it, and focus goes back to the button
 * that opened it.
 *
 * `stock` is the level as the page shows it — `{ onHand, reserved, version }`,
 * all zero when the product has no stock there yet — and is what the request
 * says it was based on. When someone else changed the level first, the API
 * answers 409, the page refetches, and the dialog stays open with the new
 * numbers so the operator can decide again.
 */
export function AdjustStockDialog({ productId, sku, location, stock, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const amountRef = useRef(null);
  const titleId = useId();
  const adjust = useAdjustStock(productId);

  const [mode, setMode] = useState('delta');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [touched, setTouched] = useState(false);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog && !dialog.open) dialog.showModal();
    // The quantity is what the operator came to type; start there, not on the mode choice.
    amountRef.current?.focus();
  }, []);

  const parsed = parseQuantity(amount);
  const onHandAfter = parsed === null ? null : mode === 'delta' ? stock.onHand + parsed : parsed;

  const problems = {
    amount:
      parsed === null
        ? t('adjust.errorWholeNumber')
        : mode === 'delta' && parsed === 0
          ? t('adjust.errorNoChange')
          : onHandAfter < 0
            ? t('adjust.errorBelowZero')
            : onHandAfter < stock.reserved
              ? t('adjust.errorBelowReserved', { reserved: formatQuantity(stock.reserved, locale) })
              : null,
    reason: reason === '' ? t('adjust.errorReason') : null,
    note: reason === 'other' && note.trim() === '' ? t('adjust.errorNote') : null,
  };
  const valid = Object.values(problems).every((problem) => problem === null);
  const conflict = adjust.error?.status === 409;

  function chooseMode(next) {
    setMode(next);
    // A count is what "count" means; don't make the operator pick it twice.
    if (next === 'count' && reason === '') setReason('count');
  }

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (!valid || adjust.isPending) return;

    adjust.mutate(
      {
        locationId: location.id,
        ...(mode === 'delta' ? { delta: parsed } : { onHand: parsed }),
        reason,
        note: note.trim() === '' ? null : note.trim(),
        expectedVersion: stock.version,
      },
      { onSuccess: () => dialogRef.current?.close() },
    );
  }

  const show = (field) => (touched ? problems[field] : null);
  const amountId = useId();
  const reasonId = useId();
  const noteId = useId();

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      onClose={onClose}
      className="m-auto w-full max-w-md rounded-lg border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/40"
    >
      <form onSubmit={submit} noValidate className="space-y-4 p-5">
        <div>
          <h2 id={titleId} className="text-lg font-semibold">
            {t('adjust.title')}
          </h2>
          <p className="text-sm text-slate-500">{t('adjust.subtitle', { sku, location: `${location.code} · ${location.name}` })}</p>
        </div>

        <dl className="grid grid-cols-3 gap-2 rounded-md bg-slate-50 p-3 text-center text-sm" aria-live="polite">
          {[
            ['stock.onHand', stock.onHand],
            ['stock.reserved', stock.reserved],
            ['stock.available', stock.onHand - stock.reserved],
          ].map(([key, value]) => (
            <div key={key}>
              <dt className="text-xs text-slate-500">{t(key)}</dt>
              <dd className="font-medium tabular-nums">{formatQuantity(value, locale)}</dd>
            </div>
          ))}
        </dl>

        {conflict ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            {t('adjust.conflict')}
          </p>
        ) : adjust.error ? (
          <ErrorNotice error={adjust.error} />
        ) : null}

        <fieldset>
          <legend className="mb-1 text-sm font-medium">{t('adjust.mode')}</legend>
          <div className="grid grid-cols-2 gap-2">
            {MODES.map((option) => (
              <label
                key={option}
                className="flex cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50"
              >
                <input type="radio" name="mode" value={option} checked={mode === option} onChange={() => chooseMode(option)} className="accent-slate-900" />
                {t(`adjust.mode.${option}`)}
              </label>
            ))}
          </div>
        </fieldset>

        <div>
          <label htmlFor={amountId} className="mb-1 block text-sm font-medium">
            {t(mode === 'delta' ? 'adjust.deltaLabel' : 'adjust.countLabel')}
          </label>
          <Input
            id={amountId}
            ref={amountRef}
            inputMode={mode === 'delta' ? 'text' : 'numeric'}
            autoComplete="off"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            aria-invalid={show('amount') ? true : undefined}
            aria-describedby={`${amountId}-hint`}
            placeholder={mode === 'delta' ? t('adjust.deltaPlaceholder') : ''}
          />
          <p id={`${amountId}-hint`} className={show('amount') ? 'mt-1 text-sm text-red-700' : 'mt-1 text-sm text-slate-500'}>
            {show('amount') ?? (onHandAfter !== null ? t('adjust.onHandAfter', { quantity: formatQuantity(onHandAfter, locale) }) : t(mode === 'delta' ? 'adjust.deltaHint' : 'adjust.countHint'))}
          </p>
        </div>

        <div>
          <label htmlFor={reasonId} className="mb-1 block text-sm font-medium">
            {t('adjust.reason')}
          </label>
          <Select
            id={reasonId}
            className="w-full"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={show('reason') ? true : undefined}
            aria-describedby={show('reason') ? `${reasonId}-error` : undefined}
          >
            <option value="">{t('adjust.chooseReason')}</option>
            {ADJUSTMENT_REASONS.map((code) => (
              <option key={code} value={code}>
                {t(`reason.${code}`)}
              </option>
            ))}
          </Select>
          {show('reason') ? (
            <p id={`${reasonId}-error`} className="mt-1 text-sm text-red-700">
              {show('reason')}
            </p>
          ) : null}
        </div>

        <div>
          <label htmlFor={noteId} className="mb-1 block text-sm font-medium">
            {t(reason === 'other' ? 'adjust.noteRequired' : 'adjust.note')}
          </label>
          <textarea
            id={noteId}
            rows={2}
            maxLength={500}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            aria-invalid={show('note') ? true : undefined}
            aria-describedby={show('note') ? `${noteId}-error` : undefined}
            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
          />
          {show('note') ? (
            <p id={`${noteId}-error`} className="mt-1 text-sm text-red-700">
              {show('note')}
            </p>
          ) : null}
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={adjust.isPending}>
            {adjust.isPending ? t('adjust.saving') : t('adjust.save')}
          </Button>
        </div>
      </form>
    </dialog>
  );
}
