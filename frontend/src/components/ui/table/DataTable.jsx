import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
  flexRender,
  functionalUpdate,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { cn } from '../../../lib/utils.js';
import { Checkbox, Skeleton } from '../primitives.jsx';
import { BulkActionBar } from './BulkActionBar.jsx';
import { withFilterDefaults } from './columns.js';
import { cellTarget, FOCUSABLE, isTyping, moveCell } from './keyboard.js';
import { TablePagination } from './TablePagination.jsx';
import { TableToolbar } from './TableToolbar.jsx';
import { EMPTY_VIEW, isDefaultView, PAGE_SIZES } from './viewState.js';

/** Pages longer than this render only the rows in view. */
export const VIRTUALIZE_ABOVE = 100;
const ROW_HEIGHT_PX = 41;
const SELECT_COLUMN = '__select';

/**
 * The list component for Kanso's tables: a semantic <table> with the ARIA grid
 * pattern on top, so a screen reader hears a table and the keyboard moves cell
 * by cell with one tab stop for the whole grid.
 *
 * The view (sort, search, filters, page) is controlled: pass `view` and
 * `onViewChange`, usually from `useUrlView()`, or leave them out and the table
 * keeps it in local state. Selection is not part of the view; it belongs to
 * the rows on screen and is cleared when the search or filters change.
 *
 * With `manual`, the caller does the sorting, filtering and paging on the
 * server: `data` is the current page and `rowCount` the total.
 *
 * With `fill`, the table takes the height its parent (a flex column) has
 * left and scrolls inside itself, so the page around it stays put: the
 * toolbar and pagination remain in view and the header row sticks.
 */
export function DataTable({
  label,
  data,
  columns,
  getRowId,
  getRowLabel,
  view: controlledView,
  onViewChange,
  defaultView = EMPTY_VIEW,
  manual = false,
  rowCount,
  loading = false,
  searchable = true,
  bulkActions = [],
  onRowActivate,
  pageSizes = PAGE_SIZES,
  emptyMessage,
  fill = false,
  className,
}) {
  const { t, locale } = useI18n();
  const labelId = useId();
  const helpId = useId();
  const gridRef = useRef(null);
  const scrollerRef = useRef(null);
  const searchRef = useRef(null);

  const [ownView, setOwnView] = useState(defaultView);
  const view = controlledView ?? ownView;
  const setView = onViewChange ?? setOwnView;
  const update = (patch) => setView({ ...view, ...patch });

  const selectable = bulkActions.length > 0;
  const [rowSelection, setRowSelection] = useState({});

  // A selection means "these rows"; a different search or filter shows different rows.
  const filterKey = JSON.stringify([view.globalFilter, view.columnFilters]);
  const [selectionFilterKey, setSelectionFilterKey] = useState(filterKey);
  if (filterKey !== selectionFilterKey) {
    setSelectionFilterKey(filterKey);
    setRowSelection({});
  }

  const collator = useMemo(() => new Intl.Collator(locale, { sensitivity: 'base', numeric: true }), [locale]);

  const allColumns = useMemo(() => {
    const own = columns.map(withFilterDefaults);
    if (!selectable) return own;

    return [
      {
        id: SELECT_COLUMN,
        enableSorting: false,
        enableGlobalFilter: false,
        meta: { label: t('table.selectAll') },
        header: ({ table }) => (
          <Checkbox
            aria-label={t('table.selectAll')}
            checked={table.getIsAllPageRowsSelected()}
            ref={(element) => {
              if (element) element.indeterminate = table.getIsSomePageRowsSelected();
            }}
            disabled={table.getRowModel().rows.length === 0}
            onChange={table.getToggleAllPageRowsSelectedHandler()}
          />
        ),
        cell: ({ row }) => (
          <Checkbox
            aria-label={t('table.selectRow', { row: getRowLabel ? getRowLabel(row.original) : row.id })}
            checked={row.getIsSelected()}
            onChange={row.getToggleSelectedHandler()}
          />
        ),
      },
      ...own,
    ];
  }, [columns, selectable, t, getRowLabel]);

  // Filter-only columns (`meta.hidden`) are never drawn.
  const columnVisibility = useMemo(
    () => Object.fromEntries(columns.filter((column) => column.meta?.hidden).map((column) => [column.id ?? column.accessorKey, false])),
    [columns],
  );

  const table = useReactTable({
    data,
    columns: allColumns,
    getRowId,
    state: {
      sorting: view.sorting,
      globalFilter: view.globalFilter,
      columnFilters: view.columnFilters,
      pagination: { pageIndex: view.pageIndex, pageSize: view.pageSize },
      rowSelection,
      columnVisibility,
    },
    onSortingChange: (updater) => update({ sorting: functionalUpdate(updater, view.sorting), pageIndex: 0 }),
    onGlobalFilterChange: (updater) => update({ globalFilter: functionalUpdate(updater, view.globalFilter) ?? '', pageIndex: 0 }),
    onColumnFiltersChange: (updater) => update({ columnFilters: functionalUpdate(updater, view.columnFilters), pageIndex: 0 }),
    onPaginationChange: (updater) => update(functionalUpdate(updater, { pageIndex: view.pageIndex, pageSize: view.pageSize })),
    onRowSelectionChange: setRowSelection,
    enableRowSelection: selectable,
    globalFilterFn: 'includesString',
    sortingFns: { locale: (a, b, id) => collator.compare(String(a.getValue(id) ?? ''), String(b.getValue(id) ?? '')) },
    manualSorting: manual,
    manualFiltering: manual,
    manualPagination: manual,
    rowCount: manual ? rowCount : undefined,
    autoResetPageIndex: false,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: manual ? undefined : getSortedRowModel(),
    getFilteredRowModel: manual ? undefined : getFilteredRowModel(),
    getPaginationRowModel: manual ? undefined : getPaginationRowModel(),
  });

  const rows = table.getRowModel().rows;
  const leafColumns = table.getVisibleLeafColumns();
  const total = manual ? (rowCount ?? 0) : table.getFilteredRowModel().rows.length;
  const pageCount = table.getPageCount();

  // A page that no longer exists (the list shrank, or the URL was edited) shows the last one.
  const pastTheEnd = !loading && view.pageIndex > 0 && view.pageIndex >= pageCount;
  useEffect(() => {
    if (pastTheEnd) setView({ ...view, pageIndex: Math.max(0, pageCount - 1) });
  }, [pastTheEnd, pageCount, view, setView]);

  const virtual = rows.length > VIRTUALIZE_ABOVE;
  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => scrollerRef.current,
    estimateSize: () => ROW_HEIGHT_PX,
    overscan: 10,
    scrollPaddingStart: ROW_HEIGHT_PX,
    enabled: virtual,
  });
  const virtualItems = virtual ? virtualizer.getVirtualItems() : null;
  const shown = virtualItems ? virtualItems.map((item) => ({ index: item.index, row: rows[item.index] })) : rows.map((row, index) => ({ index, row }));
  const paddingTop = virtualItems?.length ? virtualItems[0].start : 0;
  const paddingBottom = virtualItems?.length ? virtualizer.getTotalSize() - virtualItems[virtualItems.length - 1].end : 0;

  // The active cell of the grid: the one Tab lands on. Row -1 is the header.
  const [active, setActive] = useState({ row: -1, col: 0 });
  const current = { row: Math.min(active.row, rows.length - 1), col: Math.min(active.col, leafColumns.length - 1) };
  const moved = useRef(false);

  // One tab stop for the whole grid (roving tabindex), and focus follows the
  // active cell after a key moves it. Widgets inside cells come from column
  // renderers that know nothing of this, so their tabindex is set here.
  useLayoutEffect(() => {
    const grid = gridRef.current;
    if (!grid) return;
    const target = cellTarget(grid, current) ?? cellTarget(grid, { row: -1, col: 0 });
    for (const element of grid.querySelectorAll(`[data-cell], [data-cell] :is(${FOCUSABLE})`)) {
      element.tabIndex = element === target ? 0 : -1;
    }
    if (moved.current && cellTarget(grid, current)) {
      moved.current = false;
      target.focus();
    }
  });

  function goTo(next) {
    moved.current = true;
    if (virtual && next.row >= 0) virtualizer.scrollToIndex(next.row);
    setActive(next);
  }

  function handleFocus(event) {
    const cell = event.target.closest('[data-cell]');
    if (!cell) return;
    const [row, col] = cell.dataset.cell.split(':').map(Number);
    if (row !== current.row || col !== current.col) setActive({ row, col });
  }

  function clearSelection() {
    setRowSelection({});
  }

  function handleKeyDown(event) {
    const cell = event.target.closest('[data-cell]');
    if (!cell || isTyping(event.target)) return;
    const ctrl = event.ctrlKey || event.metaKey;
    // Where the key was pressed, from the DOM: a click can focus a cell before `active` catches up.
    const [row, col] = cell.dataset.cell.split(':').map(Number);
    const here = { row, col };

    const next = moveCell(here, event, { rows: rows.length, cols: leafColumns.length });
    if (next) {
      event.preventDefault();
      goTo(next);
      return;
    }

    if (event.key === '/' && searchRef.current) {
      event.preventDefault();
      searchRef.current.focus();
    } else if (selectable && ctrl && event.key.toLowerCase() === 'a') {
      event.preventDefault();
      table.toggleAllPageRowsSelected(!table.getIsAllPageRowsSelected());
    } else if (selectable && event.key === 'Escape' && Object.keys(rowSelection).length > 0) {
      event.preventDefault();
      clearSelection();
    } else if (event.target === cell && here.row >= 0) {
      const target = rows[here.row];
      if (event.key === ' ' && selectable) {
        event.preventDefault();
        target.toggleSelected();
      } else if (event.key === 'Enter' && onRowActivate) {
        event.preventDefault();
        onRowActivate(target.original);
      }
    }
  }

  const selectedIds = Object.keys(rowSelection).filter((id) => rowSelection[id]);
  const filtered = view.globalFilter !== '' || view.columnFilters.length > 0;
  const primarySort = view.sorting[0];

  return (
    <div className={cn(fill ? 'flex min-h-0 flex-1 flex-col gap-3' : 'space-y-3', className)}>
      <TableToolbar
        table={table}
        searchable={searchable}
        searchRef={searchRef}
        canReset={!isDefaultView(view, defaultView)}
        onReset={() => update({ sorting: defaultView.sorting, globalFilter: '', columnFilters: [], pageIndex: 0 })}
      />

      {selectable && selectedIds.length > 0 ? (
        <BulkActionBar
          actions={bulkActions}
          ids={selectedIds}
          rows={table.getSelectedRowModel().flatRows.map((row) => row.original)}
          onClear={clearSelection}
        />
      ) : null}

      <div
        ref={scrollerRef}
        className={cn('overflow-auto rounded-lg border border-slate-200 bg-white', fill ? 'min-h-0 flex-1' : virtual && 'max-h-[70vh]')}
      >
        <table
          ref={gridRef}
          role="grid"
          aria-labelledby={labelId}
          aria-describedby={helpId}
          aria-rowcount={rows.length + 1}
          aria-colcount={leafColumns.length}
          aria-multiselectable={selectable || undefined}
          aria-busy={loading || undefined}
          className="w-full border-collapse text-sm"
          onKeyDown={handleKeyDown}
          onFocus={handleFocus}
        >
          <caption id={labelId} className="sr-only">
            {label}
          </caption>
          <thead className="sticky top-0 z-10 bg-slate-50">
            {table.getHeaderGroups().map((group) => (
              <tr key={group.id} aria-rowindex={1}>
                {group.headers.map((header, col) => {
                  const { column } = header;
                  const sorted = column.getIsSorted();
                  const end = column.columnDef.meta?.align === 'end';
                  const content = header.isPlaceholder ? null : flexRender(column.columnDef.header, header.getContext());

                  return (
                    <th
                      key={header.id}
                      scope="col"
                      role="columnheader"
                      data-cell={`-1:${col}`}
                      tabIndex={-1}
                      aria-colindex={col + 1}
                      aria-sort={primarySort?.id === column.id ? (sorted === 'desc' ? 'descending' : 'ascending') : undefined}
                      className={cn(
                        'h-10 border-b border-slate-200 px-3 text-left font-medium whitespace-nowrap text-slate-600',
                        'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900',
                        column.id === SELECT_COLUMN && 'w-10',
                        end && 'text-right',
                      )}
                    >
                      {column.getCanSort() ? (
                        <button
                          type="button"
                          onClick={column.getToggleSortingHandler()}
                          className={cn(
                            'inline-flex items-center gap-1 rounded hover:text-slate-900',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                            end && 'flex-row-reverse',
                          )}
                        >
                          {content}
                          <SortIcon sorted={sorted} />
                          {view.sorting.length > 1 && sorted ? (
                            <span className="text-xs text-slate-500" aria-hidden="true">
                              {column.getSortIndex() + 1}
                            </span>
                          ) : null}
                        </button>
                      ) : (
                        content
                      )}
                    </th>
                  );
                })}
              </tr>
            ))}
          </thead>

          <tbody>
            {loading ? (
              Array.from({ length: 5 }, (_, index) => (
                <tr key={`loading-${index}`} className="border-b border-slate-100">
                  {leafColumns.map((column) => (
                    <td key={column.id} className="h-10 px-3">
                      <Skeleton className="w-3/4" />
                    </td>
                  ))}
                </tr>
              ))
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={leafColumns.length} className="px-3 py-10 text-center text-slate-500">
                  {filtered ? t('table.noMatches') : (emptyMessage ?? t('table.empty'))}
                </td>
              </tr>
            ) : (
              <>
                {paddingTop > 0 ? (
                  <tr aria-hidden="true">
                    <td colSpan={leafColumns.length} style={{ height: paddingTop, padding: 0 }} />
                  </tr>
                ) : null}
                {shown.map(({ index, row }) => (
                  <tr
                    key={row.id}
                    aria-rowindex={index + 2}
                    aria-selected={selectable ? row.getIsSelected() : undefined}
                    className="border-b border-slate-100 hover:bg-slate-50 aria-selected:bg-slate-100"
                    style={{ height: ROW_HEIGHT_PX }}
                  >
                    {row.getVisibleCells().map((cell, col) => (
                      <td
                        key={cell.id}
                        role="gridcell"
                        data-cell={`${index}:${col}`}
                        tabIndex={-1}
                        aria-colindex={col + 1}
                        className={cn(
                          'px-3 py-2 whitespace-nowrap text-slate-900',
                          'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900',
                          cell.column.columnDef.meta?.align === 'end' && 'text-right tabular-nums',
                        )}
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                ))}
                {paddingBottom > 0 ? (
                  <tr aria-hidden="true">
                    <td colSpan={leafColumns.length} style={{ height: paddingBottom, padding: 0 }} />
                  </tr>
                ) : null}
              </>
            )}
          </tbody>
        </table>
      </div>

      <p id={helpId} className="sr-only">
        {t(selectable ? 'table.keyboardHelpSelectable' : 'table.keyboardHelp')}
      </p>
      <div role="status" className="sr-only">
        {selectable && selectedIds.length > 0 ? t('table.selected', { count: new Intl.NumberFormat(locale).format(selectedIds.length) }) : ''}
      </div>

      <TablePagination table={table} total={total} pageSizes={pageSizes} />
    </div>
  );
}

function SortIcon({ sorted }) {
  const Icon = sorted === 'asc' ? ArrowUp : sorted === 'desc' ? ArrowDown : ChevronsUpDown;

  return <Icon className={cn('size-3.5', !sorted && 'text-slate-400')} aria-hidden="true" />;
}
