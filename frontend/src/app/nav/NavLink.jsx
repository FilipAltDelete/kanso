import { Link } from '@tanstack/react-router';
import { ChevronRight, Folder, FolderOpen } from 'lucide-react';
import { Badge } from '../../components/ui/primitives.jsx';
import { cn } from '../../lib/utils.js';

const row = 'flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100';

/**
 * A page in the menu. Collapsed to the rail, only its icon shows: the label
 * becomes the tooltip and the accessible name, and a count stays as a dot of
 * a number on the icon so folding the menu hides nothing that needs work.
 * A page inside a folder (`nested`) shows its icon on the rail only.
 */
export function NavLink({ href, icon: Icon, active, collapsed = false, nested = false, count = 0, countTitle, children }) {
  return (
    <Link
      to={href}
      // The router's own marking on an exact match only, where it agrees with `active`.
      activeOptions={{ exact: true, includeSearch: false }}
      aria-current={active ? 'page' : undefined}
      title={collapsed ? children : undefined}
      aria-label={collapsed ? children : undefined}
      className={cn(row, collapsed && 'relative justify-center px-0', active && 'bg-slate-100 font-medium text-slate-900')}
    >
      {Icon && (collapsed || !nested) ? <Icon aria-hidden="true" className="size-4 shrink-0 text-slate-400" /> : null}
      {collapsed ? (
        <RailCount count={count} />
      ) : (
        <>
          <span className="flex-1 truncate">{children}</span>
          {count > 0 ? (
            <Badge tone="alert" title={countTitle}>
              {count.toLocaleString()}
            </Badge>
          ) : null}
        </>
      )}
    </Link>
  );
}

/** A page on the roadmap: shown so the shape of the app is visible, not a link yet. */
export function UpcomingLink({ icon: Icon, collapsed = false, note, children }) {
  return (
    <span
      aria-disabled="true"
      title={collapsed ? `${children} (${note})` : undefined}
      className={cn(row, 'text-slate-400 hover:bg-transparent', collapsed && 'justify-center px-0')}
    >
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {collapsed ? <span className="sr-only">{children}</span> : (
        <>
          <span className="flex-1 truncate">{children}</span>
          <Badge>{note}</Badge>
        </>
      )}
    </span>
  );
}

/**
 * A folder of pages. Folded, it still shows the counts of what is inside it,
 * added up, and is marked when the page in front is one of its own.
 */
export function NavFolder({ label, open, onToggle, count = 0, countTitle, containsActive, icon, children }) {
  const Icon = icon ?? (open ? FolderOpen : Folder);
  const Chevron = icon ? null : ChevronRight;

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className={cn(row, icon ? '' : 'gap-1.5 pl-1', !open && containsActive && 'bg-slate-100 font-medium text-slate-900')}
      >
        {Chevron ? <Chevron aria-hidden="true" className={cn('size-3.5 shrink-0 text-slate-400 transition-transform', open && 'rotate-90')} /> : null}
        <Icon aria-hidden="true" className="size-4 shrink-0 text-slate-400" />
        <span className="flex-1 truncate">{label}</span>
        {!open && count > 0 ? (
          <Badge tone="alert" title={countTitle}>
            {count.toLocaleString()}
          </Badge>
        ) : null}
        {icon ? <ChevronRight aria-hidden="true" className={cn('size-3.5 shrink-0 text-slate-400 transition-transform', open && 'rotate-90')} /> : null}
      </button>
      {open ? <div className="ml-4 border-l border-slate-200 pl-1">{children}</div> : null}
    </div>
  );
}

/** A count on a collapsed menu's icon. */
export function RailCount({ count }) {
  if (!count) return null;

  return (
    <span
      aria-hidden="true"
      className="pointer-events-none absolute -top-0.5 right-0.5 min-w-4 rounded-full bg-red-50 px-1 text-center text-[10px] font-semibold leading-4 tabular-nums text-red-600"
    >
      {count > 999 ? '999+' : count.toLocaleString()}
    </span>
  );
}
