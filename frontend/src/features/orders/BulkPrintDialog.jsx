import { useEffect, useRef } from 'react';
import { FileText, RotateCw } from 'lucide-react';
import { MAX_BATCH_ORDERS, useBulkDocument, useDocument } from '../../api/documents.js';
import { Button, Dialog, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';

/**
 * Pick lists or packing slips for the orders selected in the list, as one
 * PDF (ADR-0007): each order on its own pages. Asked for as the dialog
 * opens; a worker renders it while the dialog waits, then offers the link.
 * Orders left out (cancelled, on hold, already shipped) are listed with why.
 * Written in the language the UI is in, like one order's documents.
 */
export function BulkPrintDialog({ type, ids, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const request = useBulkDocument();
  const asked = useRef(false);
  const tooMany = ids.length > MAX_BATCH_ORDERS;
  const job = useDocument(request.data?.document?.id ?? null);
  const number = (value) => new Intl.NumberFormat(locale).format(value);

  const ask = () => request.mutate({ type, locale, orderIds: ids });

  useEffect(() => {
    // Once, even when effects run twice in development.
    if (asked.current || tooMany) return;
    asked.current = true;
    ask();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- asked for once, as the dialog opens
  }, []);

  const skipped = request.data?.skipped ?? [];
  const pdf = job.data ?? request.data?.document ?? null;
  const preparing = request.isPending || (pdf !== null && (pdf.status === 'queued' || pdf.status === 'running'));
  const reason = (skip) => firstTranslation(t, [`bulkPrint.skipped.${skip.code}`], skip.message);

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t(`bulkPrint.title.${type}`, { count: number(ids.length) })}
      description={t('bulkPrint.explain')}
      onClose={onClose}
      className="max-w-lg"
    >
      <div className="space-y-4">
        {tooMany ? (
          <p role="alert" className="text-sm text-red-700">
            {t('bulkPrint.tooMany', { max: number(MAX_BATCH_ORDERS) })}
          </p>
        ) : null}

        {preparing ? <Spinner label={t('bulkPrint.preparing', { count: number(pdf?.orders.length ?? ids.length) })} /> : null}

        {pdf?.status === 'done' && pdf.downloadUrl ? (
          <a
            href={pdf.downloadUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-9 items-center gap-2 rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
          >
            <FileText className="size-4" aria-hidden="true" />
            {t(`bulkPrint.open.${type}`, { count: number(pdf.orders.length) })}
          </a>
        ) : null}

        {pdf?.status === 'failed' ? (
          <div className="flex flex-wrap items-center gap-3">
            <p role="alert" className="text-sm text-red-700">
              {t('documents.failed')}
            </p>
            <Button size="sm" variant="outline" onClick={ask}>
              <RotateCw className="size-4" aria-hidden="true" />
              {t('bulkPrint.retry')}
            </Button>
          </div>
        ) : null}

        {request.data && pdf === null && skipped.length > 0 ? (
          <p role="status" className="text-sm">
            {t('bulkPrint.nothing')}
          </p>
        ) : null}

        {skipped.length > 0 ? (
          <div className="space-y-1">
            {pdf !== null ? <p className="text-sm">{t('bulkPrint.leftOut', { count: number(skipped.length) })}</p> : null}
            <ul className="max-h-60 space-y-1 overflow-y-auto rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              {skipped.map((skip) => (
                <li key={skip.id}>
                  <span className="font-medium">{skip.number ?? t('bulkTransition.unknownOrder')}</span>: {reason(skip)}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        {request.error ? <ErrorNotice error={request.error} /> : null}
        {job.error ? <ErrorNotice error={job.error} /> : null}

        <div className="flex justify-end">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('importHistory.close')}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
