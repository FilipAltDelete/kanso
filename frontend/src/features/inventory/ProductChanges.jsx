import { useMemo } from 'react';
import { useProductEvents } from '../../api/inventory.js';
import { Badge, ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatWeight } from '../../lib/quantity.js';

/** The fields a product's history records, in the order a change lists them. */
const FIELDS = ['sku', 'name', 'barcode', 'weightGrams'];
const LABELS = { sku: 'product.sku', name: 'product.name', barcode: 'product.barcode', weightGrams: 'product.weight' };

/** What an update changed: `[{ field, from, to }]`; empty for a create. */
export function changedFields(event) {
  if (event.before === null) return [];

  return FIELDS.filter((field) => (event.before[field] ?? null) !== (event.after[field] ?? null)).map((field) => ({
    field,
    from: event.before[field] ?? null,
    to: event.after[field] ?? null,
  }));
}

/**
 * Who created and changed a product, when, through what, and what changed
 * (ADR-0013). Paged on the server, newest first; the view is in the URL
 * under its own prefix so it does not clash with the stock history's.
 */
export function ProductChanges({ productId }) {
  const { t, locale } = useI18n();
  const view = useUrlView({ prefix: 'c.' });
  const events = useProductEvents(productId, view.view);
  const dateTime = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }), [locale]);

  const columns = useMemo(() => {
    const show = (field, value) => (value === null ? '—' : field === 'weightGrams' ? formatWeight(value, locale) : String(value));

    return [
      {
        accessorKey: 'occurredAt',
        header: t('history.when'),
        enableSorting: false,
        cell: ({ getValue }) => <time dateTime={getValue()}>{dateTime.format(new Date(getValue()))}</time>,
      },
      {
        id: 'what',
        header: t('productHistory.what'),
        enableSorting: false,
        meta: { label: t('productHistory.what') },
        cell: ({ row }) => {
          const event = row.original;
          if (event.type === 'created') return t('productHistory.created');

          return (
            <ul className="space-y-0.5">
              {changedFields(event).map(({ field, from, to }) => (
                <li key={field}>
                  <span className="text-slate-500">{t(LABELS[field])}:</span> {t('history.fromTo', { from: show(field, from), to: show(field, to) })}
                </li>
              ))}
            </ul>
          );
        },
      },
      {
        accessorKey: 'source',
        header: t('productHistory.via'),
        enableSorting: false,
        cell: ({ getValue }) => <Badge tone={getValue() === 'import' ? 'amber' : 'slate'}>{t(`productHistory.source.${getValue()}`)}</Badge>,
      },
      { accessorKey: 'actorName', header: t('history.by'), enableSorting: false },
    ];
  }, [t, locale, dateTime]);

  return (
    <section aria-labelledby="changes-heading" className="space-y-2">
      <h2 id="changes-heading" className="text-base font-semibold">
        {t('productHistory.title')}
      </h2>
      {events.error ? <ErrorNotice error={events.error} /> : null}
      <DataTable
        {...view}
        manual
        searchable={false}
        label={t('productHistory.title')}
        data={events.data?.member ?? []}
        rowCount={events.data?.totalItems ?? 0}
        loading={events.isPending}
        columns={columns}
        getRowId={(event) => event.id}
        emptyMessage={t('productHistory.empty')}
      />
    </section>
  );
}
