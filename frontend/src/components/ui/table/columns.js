/**
 * Column definitions are plain TanStack Table column defs. DataTable reads a
 * few extra keys from `meta`:
 *
 *   label   the column's name as text, for filter labels and screen readers,
 *           when `header` is a render function
 *   align   'end' right-aligns the column (amounts, counts)
 *   filter  { options: [{ value, label }] } adds a select filter to the toolbar;
 *           the column filters with `equalsString` unless it names a filterFn
 */
export function columnLabel(column) {
  const { header, meta } = column.columnDef;

  return meta?.label ?? (typeof header === 'string' ? header : column.id);
}

export function withFilterDefaults(column) {
  if (!column.meta?.filter || column.filterFn) return column;

  return { ...column, filterFn: 'equalsString' };
}
