/**
 * Column definitions are plain TanStack Table column defs. DataTable reads a
 * few extra keys from `meta`:
 *
 *   label   the column's name as text, for filter labels and screen readers,
 *           when `header` is a render function
 *   align   'end' right-aligns the column (amounts, counts)
 *   filter  { options: [{ value, label }] } adds a select filter to the toolbar;
 *           the column filters with `equalsString` unless it names a filterFn.
 *           { type: 'dateRange' } adds from/to date fields; the value is
 *           "YYYY-MM-DD..YYYY-MM-DD" (either side may be empty), both days
 *           inclusive, in the viewer's time zone
 */
export function columnLabel(column) {
  const { header, meta } = column.columnDef;

  return meta?.label ?? (typeof header === 'string' ? header : column.id);
}

export function withFilterDefaults(column) {
  if (!column.meta?.filter || column.filterFn) return column;

  return { ...column, filterFn: column.meta.filter.type === 'dateRange' ? inDateRange : 'equalsString' };
}

export function parseDateRange(value) {
  const [from = '', to = ''] = String(value ?? '').split('..');

  return { from, to };
}

export function formatDateRange({ from, to }) {
  return from || to ? `${from}..${to}` : undefined;
}

/** The local calendar day of an instant, as "YYYY-MM-DD". */
function localDay(instant) {
  const date = new Date(instant);
  const pad = (number) => String(number).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function inDateRange(row, id, value) {
  const { from, to } = parseDateRange(value);
  const day = localDay(row.getValue(id));

  return (!from || day >= from) && (!to || day <= to);
}
