import { useId, useMemo } from 'react';
import { DataTable } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';

/**
 * The rows of a CSV import that failed: row number, the import's own columns
 * (`columns`, such as the SKU or order reference), the column at fault and
 * the problem in the UI's language (`problem(error)`).
 */
export function RowErrors({ errors, columns: extra, problem }) {
  const { t } = useI18n();
  const headingId = useId();
  const rows = useMemo(() => errors.map((error, index) => ({ ...error, id: String(index) })), [errors]);

  const columns = useMemo(
    () => [
      { accessorKey: 'row', header: t('import.error.row'), meta: { align: 'end' }, enableGlobalFilter: false },
      ...extra,
      { accessorKey: 'field', header: t('import.error.field'), cell: ({ getValue }) => (getValue() === 'row' ? t('import.field.row') : getValue()) },
      { id: 'problem', header: t('import.error.problem'), enableSorting: false, accessorFn: problem },
    ],
    [t, extra, problem],
  );

  return (
    <section aria-labelledby={headingId} className="space-y-2">
      <h2 id={headingId} className="text-base font-semibold">
        {t('import.errorsTitle', { count: errors.length })}
      </h2>
      <DataTable label={t('import.errorsTitle', { count: errors.length })} data={rows} columns={columns} getRowId={(row) => row.id} searchable={rows.length > 10} emptyMessage="" />
    </section>
  );
}
