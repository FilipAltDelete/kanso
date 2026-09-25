import { useId, useMemo, useRef, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { ArrowLeft, Download, FileUp, RotateCcw } from 'lucide-react';
import { IMPORT_MAX_BYTES, IMPORT_MAX_ROWS, useImportProducts } from '../../api/inventory.js';
import { Button, Card, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { DataTable } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity } from '../../lib/quantity.js';
import { firstTranslation, MAX_WEIGHT_GRAMS, useCanEditCatalog } from './catalogForm.js';

/**
 * Importing products from a CSV file, in three steps: pick a file, read the
 * preview (a dry run on the server: what would be created, updated and left
 * alone, and every row that fails), then import. Products are matched by SKU,
 * so running the same file again changes nothing.
 *
 * The file is sent twice — for the preview and for the import — rather than
 * kept on the server between the two (ADR-0006).
 */
export function ProductImportPage() {
  const { t, locale } = useI18n();
  const canEdit = useCanEditCatalog();
  const inputRef = useRef(null);
  const inputId = useId();
  const [file, setFile] = useState(null);
  const [tooLarge, setTooLarge] = useState(false);
  const preview = useImportProducts();
  const run = useImportProducts();

  function choose(event) {
    const chosen = event.target.files?.[0] ?? null;
    // The same file can be picked again after fixing it.
    event.target.value = '';
    if (!chosen) return;

    run.reset();
    setFile(chosen);
    setTooLarge(chosen.size > IMPORT_MAX_BYTES);
    if (chosen.size > IMPORT_MAX_BYTES) {
      preview.reset();
      return;
    }
    preview.mutate({ file: chosen, dryRun: true });
  }

  function startOver() {
    setFile(null);
    setTooLarge(false);
    preview.reset();
    run.reset();
    inputRef.current?.focus();
  }

  function downloadTemplate() {
    // Swedish Excel reads ";" as the separator; English Excel reads ",".
    const separator = locale === 'sv' ? ';' : ',';
    const lines = [['sku', 'name', 'barcode', 'weightGrams'], ['TEE-BLK-M', t('import.templateExample'), '7350000000001', '180']];
    const blob = new Blob(['\uFEFF' + lines.map((line) => line.join(separator)).join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'products.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  }

  const number = (value) => formatQuantity(value, locale);
  const done = run.data ?? null;
  const checked = preview.data ?? null;
  const changes = checked ? checked.created + checked.updated : 0;

  return (
    <div className="max-w-5xl space-y-6">
      <div className="space-y-2">
        <Link to="/products" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {t('products.back')}
        </Link>
        <h1 className="text-xl font-semibold">{t('import.title')}</h1>
        <p className="text-sm text-slate-500">{t('import.subtitle')}</p>
      </div>

      {canEdit ? null : <ErrorNotice error={{ message: t('import.notAllowed') }} />}

      <Card className="space-y-3 p-4">
        <h2 className="text-sm font-semibold">{t('import.formatTitle')}</h2>
        <ul className="list-inside list-disc space-y-1 text-sm text-slate-600">
          <li>{t('import.formatColumns')}</li>
          <li>{t('import.formatMatching')}</li>
          <li>{t('import.formatBlank')}</li>
          <li>{t('import.formatLimits', { rows: number(IMPORT_MAX_ROWS), size: t('import.oneMegabyte') })}</li>
        </ul>
        <div className="flex flex-wrap items-center gap-2">
          <input ref={inputRef} id={inputId} type="file" accept=".csv,text/csv" onChange={choose} disabled={!canEdit || run.isPending} className="sr-only" />
          <label
            htmlFor={inputId}
            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover [input:focus-visible+&]:outline-2 [input:focus-visible+&]:outline-offset-2 [input:focus-visible+&]:outline-slate-900 [input:disabled+&]:pointer-events-none [input:disabled+&]:opacity-50"
          >
            <FileUp className="size-4" aria-hidden="true" />
            {t(file ? 'import.chooseAnother' : 'import.choose')}
          </label>
          <Button type="button" variant="outline" onClick={downloadTemplate}>
            <Download className="size-4" aria-hidden="true" />
            {t('import.template')}
          </Button>
          {file ? <span className="text-sm text-slate-600">{file.name}</span> : null}
        </div>
      </Card>

      <div aria-live="polite" className="space-y-4">
        {tooLarge ? <ErrorNotice error={{ message: t('import.file.too_large', { size: t('import.oneMegabyte') }) }} /> : null}
        {preview.isPending ? <Spinner label={t('import.checking')} /> : null}
        {preview.error ? <FileProblems error={preview.error} /> : null}
        {run.error ? (run.error.status === 422 ? <FileProblems error={run.error} /> : <ErrorNotice error={run.error} />) : null}

        {done ? (
          <Card className="space-y-3 p-4">
            <h2 className="text-sm font-semibold">{t('import.doneTitle')}</h2>
            <Counts result={done} number={number} />
            <div className="flex flex-wrap gap-2">
              <Link
                to="/products"
                className="inline-flex h-9 items-center rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
              >
                {t('import.toProducts')}
              </Link>
              <Button type="button" variant="outline" onClick={startOver}>
                <RotateCcw className="size-4" aria-hidden="true" />
                {t('import.another')}
              </Button>
            </div>
          </Card>
        ) : checked ? (
          <Card className="space-y-3 p-4">
            <h2 className="text-sm font-semibold">{t('import.previewTitle')}</h2>
            <Counts result={checked} number={number} />
            {checked.failed > 0 ? <p className="text-sm text-slate-600">{t('import.skipNote')}</p> : null}
            <div className="flex flex-wrap gap-2">
              <Button type="button" disabled={changes === 0 || run.isPending || !canEdit} onClick={() => run.mutate({ file, dryRun: false })}>
                {run.isPending ? t('import.importing') : changes === 0 ? t('import.nothingToImport') : t('import.run', { count: number(changes) })}
              </Button>
              <Button type="button" variant="outline" onClick={startOver} disabled={run.isPending}>
                {t('common.cancel')}
              </Button>
            </div>
          </Card>
        ) : null}

        {(done ?? checked)?.errors.length ? <RowErrors errors={(done ?? checked).errors} /> : null}
      </div>
    </div>
  );
}

function Counts({ result, number }) {
  const { t } = useI18n();
  const prefix = result.dryRun ? 'import.will' : 'import.did';
  const counts = [
    ['rows', result.rows, 'slate'],
    ['created', result.created, 'green'],
    ['updated', result.updated, 'green'],
    ['unchanged', result.unchanged, 'slate'],
    ['failed', result.failed, result.failed > 0 ? 'red' : 'slate'],
  ];
  const tones = { slate: 'text-slate-900', green: 'text-green-800', red: 'text-red-700' };

  return (
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
      {counts.map(([key, value, tone]) => (
        <div key={key} className="rounded-md bg-slate-50 p-3">
          <dt className="text-xs text-slate-500">{t(key === 'rows' || key === 'failed' ? `import.count.${key}` : `${prefix}.${key}`)}</dt>
          <dd className={`text-lg font-semibold tabular-nums ${tones[tone]}`}>{number(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

/** What is wrong with the file as a whole: nothing in it was checked row by row. */
function FileProblems({ error }) {
  const { t, locale } = useI18n();
  if (error.status !== 422) return <ErrorNotice error={error} />;

  const messages = error.violations.map((violation) => {
    const column = violation.path.startsWith('header.') ? violation.path.slice('header.'.length) : '';

    return firstTranslation(t, [`import.file.${violation.code}`], violation.message, { column, rows: formatQuantity(IMPORT_MAX_ROWS, locale), size: t('import.oneMegabyte') });
  });

  return (
    <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
      {t('import.fileRejected')}
      <ul className="mt-1 list-inside list-disc">
        {messages.map((message) => (
          <li key={message}>{message}</li>
        ))}
      </ul>
    </div>
  );
}

function RowErrors({ errors }) {
  const { t, locale } = useI18n();
  const rows = useMemo(() => errors.map((error, index) => ({ ...error, id: String(index) })), [errors]);

  const columns = useMemo(
    () => [
      { accessorKey: 'row', header: t('import.error.row'), meta: { align: 'end' }, enableGlobalFilter: false },
      { accessorKey: 'sku', header: t('product.sku'), cell: ({ getValue }) => getValue() ?? '' },
      {
        accessorKey: 'field',
        header: t('import.error.field'),
        cell: ({ getValue }) => firstTranslation(t, [`import.field.${getValue()}`], getValue()),
      },
      {
        id: 'problem',
        header: t('import.error.problem'),
        enableSorting: false,
        accessorFn: (error) =>
          firstTranslation(t, [`catalog.violation.${error.field}.${error.code}`, `import.error.${error.code}`], error.message, { max: formatQuantity(MAX_WEIGHT_GRAMS, locale) }),
      },
    ],
    [t, locale],
  );

  return (
    <section aria-labelledby="import-errors" className="space-y-2">
      <h2 id="import-errors" className="text-base font-semibold">
        {t('import.errorsTitle', { count: errors.length })}
      </h2>
      <DataTable label={t('import.errorsTitle', { count: errors.length })} data={rows} columns={columns} getRowId={(row) => row.id} searchable={rows.length > 10} emptyMessage="" />
    </section>
  );
}
