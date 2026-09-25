/**
 * A list's view (sort, search, column filters, page and page size) lives in
 * the URL: a view is saved by bookmarking it and shared by sending the link,
 * and the back button returns to it. Values equal to the defaults stay out of
 * the URL, so an untouched list has a plain address.
 *
 * Parameters, each behind an optional prefix so two tables can share a page:
 *   sort=-createdAt,number   descending with "-", comma-separated for multi-sort
 *   q=anna                   the search box
 *   f.status=pending         one column filter per parameter
 *   page=3                   1-based
 *   size=100                 one of the page sizes
 */

export const PAGE_SIZES = [25, 50, 100, 250];

export const EMPTY_VIEW = Object.freeze({
  sorting: [],
  globalFilter: '',
  columnFilters: [],
  pageIndex: 0,
  pageSize: PAGE_SIZES[0],
});

/** Written when a list with a default sort is deliberately left unsorted. */
const NO_SORT = 'none';

function names(prefix = '') {
  return { sort: `${prefix}sort`, q: `${prefix}q`, page: `${prefix}page`, size: `${prefix}size`, filter: `${prefix}f.` };
}

/** The router parses `page=2` into a number and `q=true` into a boolean; the view wants text. */
function text(value) {
  if (value === undefined || value === null) return undefined;

  return String(value);
}

function positiveInteger(value) {
  const number = Number(value);

  return Number.isInteger(number) && number > 0 ? number : undefined;
}

export function parseSort(value) {
  if (!value || value === NO_SORT) return [];

  return value
    .split(',')
    .filter(Boolean)
    .map((part) => (part.startsWith('-') ? { id: part.slice(1), desc: true } : { id: part, desc: false }));
}

export function serializeSort(sorting) {
  return sorting.map(({ id, desc }) => `${desc ? '-' : ''}${id}`).join(',');
}

/**
 * The view a list starts from. Only sorting and page size can have defaults:
 * a default filter could not be turned off, because its absence from the URL
 * already means "the default".
 */
export function viewDefaults(defaults = {}) {
  return {
    ...EMPTY_VIEW,
    sorting: defaults.sorting ?? EMPTY_VIEW.sorting,
    pageSize: defaults.pageSize ?? EMPTY_VIEW.pageSize,
  };
}

export function parseView(search, { prefix, defaults, pageSizes = PAGE_SIZES } = {}) {
  const base = viewDefaults(defaults);
  const param = names(prefix);
  const sort = text(search[param.sort]);
  const size = positiveInteger(search[param.size]);
  const page = positiveInteger(search[param.page]);

  const columnFilters = Object.keys(search)
    .filter((key) => key.startsWith(param.filter) && key.length > param.filter.length)
    .map((key) => ({ id: key.slice(param.filter.length), value: text(search[key]) }))
    .filter(({ value }) => value !== undefined && value !== '');

  return {
    sorting: sort === undefined ? base.sorting : parseSort(sort),
    globalFilter: text(search[param.q]) ?? '',
    columnFilters,
    pageIndex: page ? page - 1 : 0,
    pageSize: size && pageSizes.includes(size) ? size : base.pageSize,
  };
}

/** The URL parameters for a view; a parameter at its default is `undefined`. */
export function toSearch(view, { prefix, defaults } = {}) {
  const base = viewDefaults(defaults);
  const param = names(prefix);
  const sort = serializeSort(view.sorting);

  const search = {
    [param.sort]: sort === serializeSort(base.sorting) ? undefined : sort || NO_SORT,
    [param.q]: view.globalFilter ? view.globalFilter : undefined,
    [param.page]: view.pageIndex > 0 ? view.pageIndex + 1 : undefined,
    [param.size]: view.pageSize === base.pageSize ? undefined : view.pageSize,
  };
  for (const { id, value } of view.columnFilters) {
    if (value !== undefined && value !== null && value !== '') search[`${param.filter}${id}`] = String(value);
  }

  return search;
}

/** The current search with this table's parameters replaced and everything else left alone. */
export function mergeSearch(previous, view, options = {}) {
  const param = names(options.prefix);
  const owned = new Set([param.sort, param.q, param.page, param.size]);
  const next = {};

  for (const [key, value] of Object.entries(previous ?? {})) {
    if (!owned.has(key) && !key.startsWith(param.filter)) next[key] = value;
  }
  for (const [key, value] of Object.entries(toSearch(view, options))) {
    if (value !== undefined) next[key] = value;
  }

  return next;
}

/** Whether the view narrows or reorders the list, which is what "Reset view" undoes. */
export function isDefaultView(view, defaultView) {
  return (
    view.globalFilter === '' &&
    view.columnFilters.length === 0 &&
    serializeSort(view.sorting) === serializeSort(defaultView.sorting)
  );
}
