import { useId, useRef } from 'react';
import { cn } from '../../lib/utils.js';

/**
 * Tabs with the ARIA tabs pattern: one tab stop for the whole list, arrow
 * keys (and Home/End) move between tabs and open them, and the panel is
 * labelled by its tab. Controlled: `selected` is a tab id, `onSelect` gets
 * the next one. Only the selected panel is rendered, so a tab's data loads
 * when it is opened.
 *
 * `tabs` is `[{ id, label }]`; `children` renders the panel for an id.
 */
export function Tabs({ label, tabs, selected, onSelect, children, className }) {
  const baseId = useId();
  const buttons = useRef({});
  const current = tabs.some((tab) => tab.id === selected) ? selected : tabs[0]?.id;

  function onKeyDown(event) {
    const index = tabs.findIndex((tab) => tab.id === current);
    const next = {
      ArrowRight: (index + 1) % tabs.length,
      ArrowLeft: (index - 1 + tabs.length) % tabs.length,
      Home: 0,
      End: tabs.length - 1,
    }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    onSelect(tabs[next].id);
    buttons.current[tabs[next].id]?.focus();
  }

  return (
    <div className={cn('space-y-6', className)}>
      <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto border-b border-slate-200">
        {tabs.map((tab) => {
          const active = tab.id === current;

          return (
            <button
              key={tab.id}
              ref={(element) => {
                buttons.current[tab.id] = element;
              }}
              type="button"
              role="tab"
              id={`${baseId}-tab-${tab.id}`}
              aria-selected={active}
              aria-controls={`${baseId}-panel-${tab.id}`}
              tabIndex={active ? 0 : -1}
              onClick={() => onSelect(tab.id)}
              onKeyDown={onKeyDown}
              className={cn(
                '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                active ? 'border-accent text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700',
              )}
            >
              {tab.label}
            </button>
          );
        })}
      </div>
      {current === undefined ? null : (
        <div role="tabpanel" id={`${baseId}-panel-${current}`} aria-labelledby={`${baseId}-tab-${current}`} tabIndex={0} className="rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-900">
          {children(current)}
        </div>
      )}
    </div>
  );
}
