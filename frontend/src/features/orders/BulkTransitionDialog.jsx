import { useRef, useState } from 'react';
import { BULK_TRANSITIONS, useBulkTransition } from '../../api/orderBulk.js';
import { Button, Checkbox, Dialog, ErrorNotice, Field, Select } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';

/**
 * One status change for the orders selected in the list (ADR-0015). Each
 * order moves on its own, as on its own page; the ones that cannot (short
 * stock, not allowed from their status, changed since the list loaded) are
 * listed with the reason, and the rest have moved.
 *
 * `rows` are the selected orders that are loaded; their versions go along,
 * so an order someone changed in the meantime is reported, not overwritten.
 * Selected orders on other pages are sent by id alone.
 */
export function BulkTransitionDialog({ ids, rows, onClose, onDone }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const move = useBulkTransition();
  const [transition, setTransition] = useState('');
  const [sure, setSure] = useState(false);
  const [touched, setTouched] = useState(false);
  const count = new Intl.NumberFormat(locale).format(ids.length);
  const result = move.data ?? null;

  const problem = transition === '' ? t('bulkTransition.choose') : transition === 'cancel' && !sure ? t('bulkTransition.confirmCancel') : null;

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (problem !== null || move.isPending) return;

    const versions = new Map(rows.map((row) => [row.id, row.version]));
    move.mutate(
      { transition, orders: ids.map((id) => (versions.has(id) ? { id, version: versions.get(id) } : { id })) },
      {
        onSuccess: (outcome) => {
          onDone(outcome);
          // Nothing to explain: done. Otherwise the dialog stays open with the reasons.
          if (outcome.failed.length === 0) dialogRef.current?.close();
        },
      },
    );
  }

  const reason = (failure) =>
    firstTranslation(t, [`bulkTransition.error.${failure.code}`, `violation.${failure.code}`], failure.message);

  return (
    <Dialog dialogRef={dialogRef} title={t('bulkTransition.title', { count })} onClose={onClose} className="max-w-lg">
      {result ? (
        <div className="space-y-4">
          <p role="status" className="text-sm">
            {t('bulkTransition.outcome', {
              moved: new Intl.NumberFormat(locale).format(result.moved.length),
              failed: new Intl.NumberFormat(locale).format(result.failed.length),
              transition: t(`orderTransition.${result.transition}`),
            })}
          </p>
          <ul className="max-h-72 space-y-1 overflow-y-auto rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            {result.failed.map((failure) => (
              <li key={failure.id}>
                <span className="font-medium">{failure.number ?? t('bulkTransition.unknownOrder')}</span>: {reason(failure)}
              </li>
            ))}
          </ul>
          <div className="flex justify-end">
            <Button type="button" onClick={() => dialogRef.current?.close()}>
              {t('importHistory.close')}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={submit} noValidate className="space-y-4">
          <p className="text-sm text-slate-600">{t('bulkTransition.explain')}</p>
          <Field label={t('bulkTransition.change')} error={touched && transition === '' ? problem : null}>
            {(props) => (
              <Select {...props} className="w-full" value={transition} onChange={(event) => setTransition(event.target.value)}>
                <option value="">{t('bulkTransition.chooseOption')}</option>
                {BULK_TRANSITIONS.map((name) => (
                  <option key={name} value={name}>
                    {t(`orderTransition.${name}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          {transition === 'cancel' ? (
            <div className="space-y-1">
              <div className="flex items-start gap-2">
                <Checkbox id="bulk-cancel-sure" checked={sure} onChange={(event) => setSure(event.target.checked)} aria-describedby={touched && !sure ? 'bulk-cancel-error' : undefined} />
                <label htmlFor="bulk-cancel-sure" className="text-sm">
                  {t('bulkTransition.cancelSure', { count })}
                </label>
              </div>
              {touched && !sure ? (
                <p id="bulk-cancel-error" className="text-xs text-red-700">
                  {problem}
                </p>
              ) : null}
            </div>
          ) : null}

          {move.error ? <ErrorNotice error={move.error} /> : null}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" variant={transition === 'cancel' ? 'danger' : 'default'} disabled={move.isPending}>
              {move.isPending ? t('bulkTransition.applying') : t('bulkTransition.apply', { count })}
            </Button>
          </div>
        </form>
      )}
    </Dialog>
  );
}
