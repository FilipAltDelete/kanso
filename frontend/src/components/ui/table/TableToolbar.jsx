import { useEffect, useId, useState } from 'react';
import { RotateCcw, Search } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { Button, Input, Select } from '../primitives.jsx';
import { columnLabel, formatDateRange, parseDateRange } from './columns.js';
import { MultiFilter } from './MultiFilter.jsx';

/** Typing settles for this long before the view (and the URL) follows. */
const SEARCH_DELAY_MS = 250;

export function TableToolbar({ table, searchable, searchRef, canReset, onReset }) {
  const { t } = useI18n();
  const filterable = table.getAllLeafColumns().filter((column) => column.columnDef.meta?.filter);

  return (
    <div className="flex flex-wrap items-end gap-3">
      {searchable ? (
        <SearchField
          inputRef={searchRef}
          value={table.getState().globalFilter ?? ''}
          onChange={(value) => table.setGlobalFilter(value)}
        />
      ) : null}

      {filterable.map((column) => (
        <ColumnFilter key={column.id} column={column} />
      ))}

      {canReset ? (
        <Button variant="ghost" size="sm" onClick={onReset}>
          <RotateCcw className="size-3.5" aria-hidden="true" />
          {t('table.resetView')}
        </Button>
      ) : null}
    </div>
  );
}

function SearchField({ value, onChange, inputRef }) {
  const { t } = useI18n();
  const id = useId();
  const [draft, setDraft] = useState(value);
  const [committed, setCommitted] = useState(value);

  // The view changed from outside (back button, reset): show what it says.
  if (value !== committed) {
    setCommitted(value);
    setDraft(value);
  }

  useEffect(() => {
    if (draft === value) return undefined;
    const timer = setTimeout(() => onChange(draft), SEARCH_DELAY_MS);

    return () => clearTimeout(timer);
  }, [draft, value, onChange]);

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-600">
        {t('table.search')}
      </label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
        <Input
          id={id}
          ref={inputRef}
          type="search"
          className="w-64 pl-8"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') onChange(draft);
          }}
          placeholder={t('table.searchPlaceholder')}
          autoComplete="off"
        />
      </div>
    </div>
  );
}

function ColumnFilter({ column }) {
  const { t } = useI18n();
  const id = useId();
  const { options, type } = column.columnDef.meta.filter;

  if (type === 'dateRange') return <DateRangeFilter column={column} />;
  if (type === 'multi') return <MultiFilter column={column} />;

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-600">
        {columnLabel(column)}
      </label>
      <Select id={id} value={column.getFilterValue() ?? ''} onChange={(event) => column.setFilterValue(event.target.value || undefined)}>
        <option value="">{t('table.filterAll')}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </div>
  );
}

function DateRangeFilter({ column }) {
  const { t } = useI18n();
  const id = useId();
  const range = parseDateRange(column.getFilterValue());
  const set = (patch) => column.setFilterValue(formatDateRange({ ...range, ...patch }));

  return (
    <fieldset className="flex items-end gap-2">
      <legend className="sr-only">{columnLabel(column)}</legend>
      <div className="flex flex-col gap-1">
        <label htmlFor={`${id}-from`} className="text-xs font-medium text-slate-600">
          {t('table.dateFrom', { column: columnLabel(column) })}
        </label>
        <Input id={`${id}-from`} type="date" className="w-40" value={range.from} max={range.to || undefined} onChange={(event) => set({ from: event.target.value })} />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor={`${id}-to`} className="text-xs font-medium text-slate-600">
          {t('table.dateTo')}
        </label>
        <Input id={`${id}-to`} type="date" className="w-40" value={range.to} min={range.from || undefined} onChange={(event) => set({ to: event.target.value })} />
      </div>
    </fieldset>
  );
}
