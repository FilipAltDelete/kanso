import { useState } from 'react';
import { FileText, Printer, RotateCw } from 'lucide-react';
import { DOCUMENT_TYPES, useDocument, useRequestDocument } from '../../api/documents.js';
import { Button, ErrorNotice } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';

/**
 * "Print" for an order: asks the API for a pick list or packing slip, waits
 * while a worker renders it, then offers the PDF. The link opens the store's
 * signed URL in a new tab, where the browser's PDF viewer prints it. The
 * document is written in the language the UI is in.
 */
export function PrintDocuments({ order }) {
  const { t } = useI18n();

  return (
    <section aria-labelledby="order-print" className="space-y-2">
      <h2 id="order-print" className="text-sm font-semibold text-slate-900">
        {t('documents.print')}
      </h2>
      <div className="flex flex-wrap gap-3">
        {DOCUMENT_TYPES.map((type) => (
          // Keyed by version: a changed order is printed afresh, not from what was asked before.
          <PrintDocument key={`${type}-${order.version}`} order={order} type={type} />
        ))}
      </div>
    </section>
  );
}

/** One document's button, link and status; `shipmentId` makes it the packing slip of that parcel. */
export function PrintDocument({ order, type, shipmentId = null, label: customLabel = null }) {
  const { t, locale } = useI18n();
  const request = useRequestDocument(order.id);
  const [documentId, setDocumentId] = useState(null);
  const job = useDocument(documentId);
  const data = job.data;

  const ask = () => request.mutate({ type, locale, shipmentId }, { onSuccess: (created) => setDocumentId(created.id) });
  const busy = request.isPending || (documentId !== null && (!data || data.status === 'queued' || data.status === 'running'));
  const label = customLabel ?? t(`documents.type.${type}`);
  const statusId = `print-${type}-${shipmentId ?? 'order'}-status`;
  // The first document of the whole order (the pick list) is what the "p" shortcut prints.
  const shortcut = type === DOCUMENT_TYPES[0] && shipmentId === null ? 'print' : undefined;

  return (
    <div className="flex flex-wrap items-center gap-2">
      {data?.status === 'done' && data.downloadUrl ? (
        <a
          href={data.downloadUrl}
          data-shortcut={shortcut}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-8 items-center gap-2 rounded-md bg-accent px-3 text-xs font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        >
          <FileText className="size-4" aria-hidden="true" />
          {t(`documents.open.${type}`)}
        </a>
      ) : (
        <Button size="sm" variant="outline" data-shortcut={shortcut} onClick={ask} disabled={busy} aria-describedby={statusId}>
          {data?.status === 'failed' ? <RotateCw className="size-4" aria-hidden="true" /> : <Printer className="size-4" aria-hidden="true" />}
          {data?.status === 'failed' ? t(`documents.retry.${type}`) : label}
        </Button>
      )}
      <span id={statusId} role="status" className="text-xs text-slate-600">
        {busy ? t('documents.preparing') : data?.status === 'failed' ? t('documents.failed') : ''}
      </span>
      {request.error ? <ErrorNotice error={request.error} /> : null}
      {job.error ? <ErrorNotice error={job.error} /> : null}
    </div>
  );
}
