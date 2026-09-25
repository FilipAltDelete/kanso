import { useEffect, useId, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { cn } from '../../../lib/utils.js';
import { Button, Checkbox } from '../primitives.jsx';
import { columnLabel, formatList, parseList } from './columns.js';
import { FOCUSABLE } from './keyboard.js';

/**
 * A filter that takes several values: a button that shows what is chosen and
 * discloses a group of real checkboxes (the WAI-ARIA disclosure pattern, so a
 * screen reader hears a button, then ordinary checkboxes in a labelled group).
 * Escape, a click outside or tabbing away closes it; each tick applies at once.
 */
export function MultiFilter({ column }) {
  const { t, locale } = useI18n();
  const id = useId();
  const rootRef = useRef(null);
  const buttonRef = useRef(null);
  const panelRef = useRef(null);
  const [open, setOpen] = useState(false);
  const { options = [], presets = [] } = column.columnDef.meta.filter;
  const label = columnLabel(column);

  const chosen = parseList(column.getFilterValue());
  // A value from a link whose option is not loaded (or no longer exists) still shows, and can be unticked.
  const all = [...options, ...chosen.filter((value) => !options.some((option) => option.value === value)).map((value) => ({ value, label: value }))];
  const set = (values) => column.setFilterValue(formatList(values, options));
  const toggle = (value) => set(chosen.includes(value) ? chosen.filter((item) => item !== value) : [...chosen, value]);
  const sameAs = (value) => {
    const preset = parseList(value);
    return preset.length === chosen.length && preset.every((item) => chosen.includes(item));
  };

  const activePreset = presets.find((preset) => sameAs(preset.value));
  const labelOf = (value) => all.find((option) => option.value === value)?.label ?? value;
  const summary =
    chosen.length === 0
      ? t('table.filterAll')
      : activePreset
        ? activePreset.label
        : chosen.length <= 2
          ? chosen.map(labelOf).join(', ')
          : t('table.filterCount', { count: new Intl.NumberFormat(locale).format(chosen.length) });

  function close({ focusButton = false } = {}) {
    setOpen(false);
    if (focusButton) buttonRef.current?.focus();
  }

  // Opening hands focus to the first choice, so the keyboard lands where the work is.
  useEffect(() => {
    if (open) panelRef.current?.querySelector(FOCUSABLE)?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const outside = (event) => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    // Escape inside the panel closes it and hands focus back to its button.
    const escape = (event) => {
      if (event.key !== 'Escape' || !panelRef.current?.contains(event.target)) return;
      event.preventDefault();
      setOpen(false);
      buttonRef.current?.focus();
    };
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', escape);

    return () => {
      document.removeEventListener('pointerdown', outside);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);

  return (
    <div
      ref={rootRef}
      className="relative flex flex-col gap-1"
      onBlur={(event) => {
        if (open && event.relatedTarget && !rootRef.current?.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      <span id={`${id}-label`} className="text-xs font-medium text-slate-600">
        {label}
      </span>
      <button
        ref={buttonRef}
        id={`${id}-button`}
        type="button"
        aria-labelledby={`${id}-label ${id}-button`}
        aria-expanded={open}
        aria-controls={`${id}-panel`}
        onClick={() => setOpen((current) => !current)}
        className={cn(
          'inline-flex h-9 max-w-56 items-center justify-between gap-2 rounded-md border border-slate-300 bg-white px-2 text-left text-sm',
          'focus-visible:outline-2 focus-visible:outline-slate-900',
          chosen.length > 0 && 'font-medium text-slate-900',
        )}
      >
        <span className="truncate">{summary}</span>
        <ChevronDown className={cn('size-4 shrink-0 text-slate-500 transition-transform', open && 'rotate-180')} aria-hidden="true" />
      </button>

      <div
        ref={panelRef}
        id={`${id}-panel`}
        hidden={!open}
        className="absolute left-0 top-full z-20 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-md border border-slate-200 bg-white p-2 shadow-lg"
      >
        {open ? (
          <fieldset className="space-y-2">
            <legend className="sr-only">{label}</legend>
            {presets.length > 0 ? (
              <div className="flex flex-wrap gap-1 border-b border-slate-100 pb-2">
                {presets.map((preset) => (
                  <Button key={preset.value} size="sm" variant={activePreset === preset ? 'default' : 'outline'} aria-pressed={activePreset === preset} onClick={() => set(parseList(preset.value))}>
                    {preset.label}
                  </Button>
                ))}
              </div>
            ) : null}
            {all.length === 0 ? (
              <p className="px-1 py-1.5 text-sm text-slate-500">{t('table.filterNoOptions')}</p>
            ) : (
              <ul className="max-h-64 overflow-y-auto">
                {all.map((option) => (
                  <li key={option.value}>
                    <label className="flex cursor-pointer items-center gap-2 rounded px-1 py-1.5 text-sm hover:bg-slate-50">
                      <Checkbox checked={chosen.includes(option.value)} onChange={() => toggle(option.value)} />
                      <span className="truncate">{option.label}</span>
                    </label>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-end gap-2 border-t border-slate-100 pt-2">
              <Button size="sm" variant="ghost" disabled={chosen.length === 0} onClick={() => set([])}>
                {t('table.filterClear')}
              </Button>
              <Button size="sm" variant="outline" onClick={() => close({ focusButton: true })}>
                {t('table.filterDone')}
              </Button>
            </div>
          </fieldset>
        ) : null}
      </div>
    </div>
  );
}
