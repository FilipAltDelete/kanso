import { useMemo, useRef, useState } from 'react';
import { useImportRun, useImportRuns } from '../../api/importRuns.js';
import { Button, Dialog, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity } from '../../lib/quantity.js';
import { RowErrors } from './RowErrors.jsx';

/**
 * The imports of one kind that were run for real (ADR-0014): when, who,
 * which file and what came of it, newest first. A run's problem rows open in
 * a dialog, so a file fixed after the fact can still be checked against
 * what went wrong.
 *
 * `counts(result)` and `errorColumns`/`problem` are the import page's own,
 * so a past run reads like the result the page showed at the time.
 */
export function ImportHistory({ type, counts, errorColumns, problem }) {
  const { t, locale } = useI18n();
  const view = useUrlView({ prefix: 'r.', defaults: { pageSize: 10 } });
  const runs = useImportRuns(type, view.view);
  const [open, setOpen] = useState(null);
  const dateTime = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }), [locale]);

  const columns = useMemo(
    () => [
      {
        accessorKey: 'startedAt',
        header: t('history.when'),
        enableSorting: false,
        cell: ({ getValue }) => <time dateTime={getValue()}>{dateTime.format(new Date(getValue()))}</time>,
      },
      { accessorKey: 'actorName', header: t('history.by'), enableSorting: false },
      { accessorKey: 'filename', header: t('importHistory.file'), enableSorting: false, cell: ({ getValue }) => getValue() ?? '—' },
      {
        id: 'result',
        header: t('importHistory.result'),
        enableSorting: false,
        meta: { label: t('importHistory.result') },
        cell: ({ row }) =>
          counts({ ...row.original.counts, dryRun: false })
            .filter(({ key }) => key !== 'failed')
            .map(({ label, value }) => `${label} ${formatQuantity(value ?? 0, locale)}`)
            .join(' · '),
      },
      {
        accessorKey: 'errorCount',
        header: t('importHistory.problems'),
        enableSorting: false,
        meta: { align: 'end' },
        cell: ({ row }) =>
          row.original.errorCount === 0 ? (
            '0'
          ) : (
            <Button variant="outline" size="sm" onClick={() => setOpen(row.original)}>
              {t('importHistory.showProblems', { count: formatQuantity(row.original.errorCount, locale) })}
            </Button>
          ),
      },
    ],
    [t, locale, dateTime, counts],
  );

  return (
    <section aria-labelledby="import-history" className="space-y-2">
      <h2 id="import-history" className="text-base font-semibold">
        {t('importHistory.title')}
      </h2>
      {runs.error ? <ErrorNotice error={runs.error} /> : null}
      <DataTable
        {...view}
        manual
        searchable={false}
        label={t('importHistory.title')}
        data={runs.data?.member ?? []}
        rowCount={runs.data?.totalItems ?? 0}
        loading={runs.isPending}
        columns={columns}
        getRowId={(run) => run.id}
        emptyMessage={t('importHistory.empty')}
      />
      {open ? <RunProblems run={open} errorColumns={errorColumns} problem={problem} onClose={() => setOpen(null)} /> : null}
    </section>
  );
}

function RunProblems({ run, errorColumns, problem, onClose }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const detail = useImportRun(run.id);
  const kept = detail.data?.errors ?? [];

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t('importHistory.problemsTitle', { file: run.filename ?? '—' })}
      description={t('importHistory.problemsBy', { by: run.actorName, when: new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(run.startedAt)) })}
      onClose={onClose}
      className="max-w-4xl"
    >
      {detail.isPending ? <Spinner label={t('common.loading')} /> : null}
      {detail.error ? <ErrorNotice error={detail.error} /> : null}
      {kept.length > 0 ? <RowErrors errors={kept} columns={errorColumns} problem={problem} /> : null}
      {run.errorCount > kept.length && !detail.isPending ? (
        <p className="text-sm text-slate-600">{t('importHistory.truncated', { kept: formatQuantity(kept.length, locale), count: formatQuantity(run.errorCount, locale) })}</p>
      ) : null}
      <div className="flex justify-end">
        <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
          {t('importHistory.close')}
        </Button>
      </div>
    </Dialog>
  );
}
