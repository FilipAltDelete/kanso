import { useId, useRef, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { ArrowLeft, Download, FileUp, RotateCcw } from 'lucide-react';
import { Button, Card, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity } from '../../lib/quantity.js';
import { firstTranslation } from '../../lib/translate.js';
import { ImportHistory } from './ImportHistory.jsx';
import { RowErrors } from './RowErrors.jsx';

/** The server's limits for one CSV file (Application/Import/CsvFile); checked here first so a too-large file is never sent. */
export const IMPORT_MAX_BYTES = 1024 * 1024;
export const IMPORT_MAX_ROWS = 5000;

const TONES = { slate: 'text-slate-900', green: 'text-green-800', red: 'text-red-700' };

/**
 * A CSV import in three steps (ADR-0006): pick a file, read the preview (a
 * dry run on the server: the counts and every row that fails), then import
 * the same file for real. The file is sent twice rather than kept on the
 * server between the two.
 *
 * What differs between imports is passed in:
 * - `preview`, `run`: two instances of the import mutation (`{ file, dryRun }`)
 * - `counts(result)`: `[{ key, label, value, tone }]` for the summary
 * - `changes(result)`: how many things the import would create or change
 * - `runLabel(count)`, `format` (lines describing the file), `template` ({ filename, rows })
 * - `errorColumns`: extra columns for the problem table, after the row number
 * - `problem(error)`: a row error in the UI's language
 * - `notAllowed`: what a viewer is told; the product import's message when left out
 * - `history`: `products`, `orders` or `stock`, to list the past imports of that kind below (ADR-0014)
 */
export function CsvImportPage({ title, subtitle, back, done: doneLink, canImport, notAllowed, preview, run, counts, changes, runLabel, format, template, errorColumns, problem, history }) {
  const { t, locale } = useI18n();
  const inputRef = useRef(null);
  const inputId = useId();
  const [file, setFile] = useState(null);
  const [tooLarge, setTooLarge] = useState(false);

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
    const text = template.rows.map((row) => row.join(separator)).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob(['﻿' + text + '\r\n'], { type: 'text/csv;charset=utf-8' }));
    link.download = template.filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  const number = (value) => formatQuantity(value, locale);
  const done = run.data ?? null;
  const checked = preview.data ?? null;
  const pending = checked ? changes(checked) : 0;

  return (
    <div className="max-w-5xl space-y-6">
      <div className="space-y-2">
        <Link to={back.to} className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {back.label}
        </Link>
        <h1 className="text-xl font-semibold">{title}</h1>
        <p className="text-sm text-slate-500">{subtitle}</p>
      </div>

      {canImport ? null : <ErrorNotice error={{ message: notAllowed ?? t('import.notAllowed') }} />}

      <Card className="space-y-3 p-4">
        <h2 className="text-sm font-semibold">{t('import.formatTitle')}</h2>
        <ul className="list-inside list-disc space-y-1 text-sm text-slate-600">
          {format.map((line) => (
            <li key={line}>{line}</li>
          ))}
          <li>{t('import.formatLimits', { rows: number(IMPORT_MAX_ROWS), size: t('import.oneMegabyte') })}</li>
        </ul>
        <div className="flex flex-wrap items-center gap-2">
          <input ref={inputRef} id={inputId} type="file" accept=".csv,text/csv" onChange={choose} disabled={!canImport || run.isPending} className="sr-only" />
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
        {run.error ? <FileProblems error={run.error} /> : null}

        {done ? (
          <Card className="space-y-3 p-4">
            <h2 className="text-sm font-semibold">{t('import.doneTitle')}</h2>
            <Counts items={counts(done)} number={number} />
            <div className="flex flex-wrap gap-2">
              <Link
                to={doneLink.to}
                className="inline-flex h-9 items-center rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
              >
                {doneLink.label}
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
            <Counts items={counts(checked)} number={number} />
            {checked.failed > 0 ? <p className="text-sm text-slate-600">{t('import.skipNote')}</p> : null}
            <div className="flex flex-wrap gap-2">
              <Button type="button" disabled={pending === 0 || run.isPending || !canImport} onClick={() => run.mutate({ file, dryRun: false })}>
                {run.isPending ? t('import.importing') : pending === 0 ? t('import.nothingToImport') : runLabel(number(pending))}
              </Button>
              <Button type="button" variant="outline" onClick={startOver} disabled={run.isPending}>
                {t('common.cancel')}
              </Button>
            </div>
          </Card>
        ) : null}

        {(done ?? checked)?.errors.length ? <RowErrors errors={(done ?? checked).errors} columns={errorColumns} problem={problem} /> : null}
      </div>

      {history ? <ImportHistory type={history} counts={counts} errorColumns={errorColumns} problem={problem} /> : null}
    </div>
  );
}

function Counts({ items, number }) {
  return (
    <dl className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-3">
      {items.map(({ key, label, value, tone = 'slate' }) => (
        <div key={key} className="rounded-md bg-slate-50 p-3">
          <dt className="text-xs text-slate-500">{label}</dt>
          <dd className={`text-lg font-semibold tabular-nums ${TONES[tone]}`}>{number(value)}</dd>
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
