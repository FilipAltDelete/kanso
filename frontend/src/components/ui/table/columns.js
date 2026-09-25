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
 *           { type: 'multi', options, presets? } adds a checkbox list in a
 *           popover; the value is the chosen values comma-joined ("a,b", in
 *           the order of `options`), so a single value from an older link
 *           still reads as a one-item choice. A row matches when its value
 *           (or any item of an array value) is one of them. `presets`
 *           ([{ value: "a,b", label }]) are one-click choices of several.
 */
export function columnLabel(column) {
  const { header, meta } = column.columnDef;

  return meta?.label ?? (typeof header === 'string' ? header : column.id);
}

export function withFilterDefaults(column) {
  if (!column.meta?.filter || column.filterFn) return column;
  if (column.meta.filter.type === 'multi') return { ...column, filterFn: inList };

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

/** A multi filter's value as a list: "a,b" is ['a', 'b']. */
export function parseList(value) {
  return String(value ?? '')
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean);
}

/**
 * The value for a list of choices, in the order of `options` so one choice
 * has one URL; values the options do not (yet) know keep their place after.
 */
export function formatList(values, options = []) {
  const chosen = new Set(values);
  const known = options.map((option) => option.value).filter((value) => chosen.has(value));
  const unknown = [...chosen].filter((value) => !known.includes(value));
  const list = [...known, ...unknown];

  return list.length > 0 ? list.join(',') : undefined;
}

function inList(row, id, value) {
  const wanted = parseList(value);
  if (wanted.length === 0) return true;
  const cell = row.getValue(id);
  const have = Array.isArray(cell) ? cell.map(String) : [String(cell ?? '')];

  return have.some((item) => wanted.includes(item));
}
