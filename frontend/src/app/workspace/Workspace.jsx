import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { FileText, LayoutDashboard, MapPin, Package, Settings, ShoppingCart, Table2, Upload, Users, X } from 'lucide-react';
import { FrontTabProvider } from '../../lib/frontTab.js';
import { useI18n } from '../../lib/i18n.jsx';
import { cn } from '../../lib/utils.js';
import { createTabRouter } from '../router.jsx';
import { MAX_PANES, activeTab, canClose, layoutBoxes, pathOf, paneIds } from './workspace.js';
import { newId, useWorkspace } from './WorkspaceProvider.jsx';

const TAB_TYPE = 'application/x-kanso-tab';

/** The tab bar's height in pixels (`h-10`); a pane's page starts below it. */
const BAR = 40;

/** How close to a pane's edge, as a share of its size, a dropped tab splits it. */
const EDGE = 0.3;

const pct = (fraction) => `${fraction * 100}%`;

/**
 * The open pages as tabs, in panes split side by side and one above another
 * (as in Pimsen).
 *
 * Drag a tab along a tab bar to reorder it, into the middle of another pane
 * to move it there, or towards any edge of a pane to split that pane and open
 * the tab on that side. A page dragged in from the menu opens where it is
 * dropped. The lines between panes resize them.
 *
 * Every pane, tab bar and page is placed absolutely from `layoutBoxes()`, in
 * one flat list keyed by id, rather than nested the way the split tree is. A
 * nested render would tear down a pane each time it is split — and every page
 * in it with its scroll position, its open dialog and whatever was typed into
 * it. Placed flat, a page survives being split beside, and moved to another
 * pane, untouched.
 */
export function Workspace() {
  const { t } = useI18n();
  const { state, dispatch, dragging, setDragging } = useWorkspace();
  const container = useRef(null);

  useCloseShortcut(state, dispatch);

  const boxes = layoutBoxes(state.layout);
  const ids = paneIds(state.layout);
  const paneOfTab = {};
  for (const pane of Object.values(state.panes)) for (const tab of pane.tabs) paneOfTab[tab] = pane.id;

  const draggedTab = dragging?.tab ?? null;
  const source = draggedTab !== null ? state.panes[paneOfTab[draggedTab]] : null;

  const drag = {
    active: dragging !== null,
    tab: draggedTab,
    source: source?.id ?? null,
    // A split needs room for one more pane — unless a dragged tab's own pane
    // closes behind it — and cannot split a pane off its only tab. A page from
    // the menu is a new tab, so it only needs the room.
    canSplit: (paneId) =>
      dragging?.href !== undefined
        ? ids.length < MAX_PANES
        : source !== null &&
          !(source.id === paneId && source.tabs.length < 2) &&
          (ids.length < MAX_PANES || source.tabs.length === 1),
    start: (tab) => setDragging({ tab }),
    end: () => setDragging(null),
    drop: (target) => {
      const fresh = { newPane: newId('pane'), newSplit: newId('split') };
      if (dragging?.tab !== undefined) dispatch({ type: 'move', tab: dragging.tab, ...fresh, ...target });
      else if (dragging?.href !== undefined) dispatch({ type: 'place', href: dragging.href, id: newId('tab'), ...fresh, ...target });
      setDragging(null);
    },
  };

  return (
    <div ref={container} className="relative min-h-0 flex-1 overflow-hidden">
      {ids.map((id) => (
        <TabBar key={id} pane={state.panes[id]} box={boxes.panes[id]} highlight={ids.length > 1 && state.focused === id} drag={drag} />
      ))}

      {Object.keys(state.tabs).map((id) => {
        const paneId = paneOfTab[id];
        if (!paneId) return null;
        const active = state.panes[paneId].active === id;
        return <TabView key={id} id={id} pane={paneId} active={active} front={active && state.focused === paneId} box={boxes.panes[paneId]} />;
      })}

      {ids
        .filter((id) => state.panes[id].tabs.length === 0)
        .map((id) => (
          <div key={id} className="absolute flex items-center justify-center p-6 text-sm text-slate-500" style={bodyOf(boxes.panes[id])}>
            {t('workspace.empty')}
          </div>
        ))}

      {boxes.splits.map((split) => (
        <Splitter key={split.id} split={split} container={container} />
      ))}

      {drag.active ? ids.map((id) => <DropZones key={id} pane={state.panes[id]} box={boxes.panes[id]} drag={drag} />) : null}
    </div>
  );
}

/**
 * Alt+W closes the tab in front — the active tab of the pane last used. Not
 * Ctrl+W, which the browser keeps for itself and closes the whole app with.
 * Matched on the physical key, because on a Mac Alt+W types "∑".
 */
function useCloseShortcut(state, dispatch) {
  const latest = useRef(state);
  latest.current = state;

  useEffect(() => {
    function onKeyDown(event) {
      if (event.code !== 'KeyW' || !event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
      event.preventDefault();
      // Held down, it closes one tab, not every tab in turn.
      if (event.repeat) return;

      const tab = activeTab(latest.current);
      if (tab) dispatch({ type: 'close', tab: tab.id });
    }

    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [dispatch]);
}

function barOf(box) {
  return { left: pct(box.x), top: pct(box.y), width: pct(box.w), height: BAR };
}

function bodyOf(box) {
  return { left: pct(box.x), top: `calc(${pct(box.y)} + ${BAR}px)`, width: pct(box.w), height: `calc(${pct(box.h)} - ${BAR}px)` };
}

const ZONE_BOXES = {
  left: 'inset-y-2 left-2 w-[calc(50%-0.5rem)]',
  right: 'inset-y-2 right-2 w-[calc(50%-0.5rem)]',
  top: 'inset-x-2 top-2 h-[calc(50%-0.5rem)]',
  bottom: 'inset-x-2 bottom-2 h-[calc(50%-0.5rem)]',
  center: 'inset-2',
};

/**
 * Where a dragged tab can land on a pane's page: near an edge splits the pane
 * and opens the tab on that side; the middle of another pane moves it there.
 * Laid over the page while a tab is dragged, so the page underneath does not
 * swallow the events.
 */
function DropZones({ pane, box, drag }) {
  const { t } = useI18n();
  const [zone, setZone] = useState(null);
  const own = drag.source === pane.id;
  const splits = drag.canSplit(pane.id);

  if (own && !splits) return null;

  const zoneAt = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width;
    const y = (event.clientY - rect.top) / rect.height;

    if (splits) {
      const distance = { left: x, right: 1 - x, top: y, bottom: 1 - y };
      const side = Object.keys(distance).reduce((nearest, candidate) => (distance[candidate] < distance[nearest] ? candidate : nearest));
      if (distance[side] < EDGE) return side;
    }

    return own ? null : 'center';
  };

  return (
    <div
      className="absolute z-40"
      style={bodyOf(box)}
      onDragOver={(event) => {
        const next = zoneAt(event);
        if (next !== null) {
          event.preventDefault();
          event.dataTransfer.dropEffect = drag.tab !== null ? 'move' : 'copy';
        }
        setZone(next);
      }}
      onDragLeave={() => setZone(null)}
      onDrop={(event) => {
        const target = zoneAt(event);
        if (target === null) return;
        event.preventDefault();
        drag.drop(target === 'center' ? { pane: pane.id } : { pane: pane.id, side: target });
      }}
    >
      {zone !== null ? (
        <div
          className={cn(
            'pointer-events-none absolute flex items-center justify-center rounded-lg border-2 border-dashed border-accent bg-accent/10 text-sm font-medium text-slate-900 backdrop-blur-[1px]',
            ZONE_BOXES[zone],
          )}
        >
          {zone === 'center' ? t(drag.tab === null ? 'workspace.openHere' : 'workspace.moveHere') : t(`workspace.split.${zone}`)}
        </div>
      ) : null}
    </div>
  );
}

function TabBar({ pane, box, highlight, drag }) {
  const { t } = useI18n();
  const { state, dispatch } = useWorkspace();
  const bar = useRef(null);
  const [insertAt, setInsertAt] = useState(null);

  // Where a tab dropped at this pointer position lands: before the first tab
  // whose middle is right of the pointer.
  const indexAt = (clientX) => {
    const tabs = [...bar.current.querySelectorAll('[data-tab]')];
    const index = tabs.findIndex((tab) => {
      const rect = tab.getBoundingClientRect();
      return clientX < rect.left + rect.width / 2;
    });
    return index === -1 ? tabs.length : index;
  };

  return (
    <div
      ref={bar}
      style={barOf(box)}
      className={cn('absolute border-b border-slate-200 bg-slate-100 px-2 pt-1.5', highlight && 'bg-slate-200/70')}
      onPointerDownCapture={() => dispatch({ type: 'focus', pane: pane.id })}
      onDragOver={(event) => {
        if (!drag.active) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = drag.tab !== null ? 'move' : 'copy';
        setInsertAt(indexAt(event.clientX));
      }}
      onDragLeave={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setInsertAt(null);
      }}
      onDrop={(event) => {
        if (!drag.active) return;
        event.preventDefault();
        drag.drop({ pane: pane.id, index: indexAt(event.clientX) });
        setInsertAt(null);
      }}
    >
      <div role="tablist" aria-label={t('workspace.tabs')} className="flex h-full items-end gap-1 overflow-x-auto">
        {pane.tabs.map((id, index) => (
          <Tab
            key={id}
            tab={state.tabs[id]}
            active={pane.active === id}
            dragging={drag.tab === id}
            insertBefore={insertAt === index}
            onActivate={() => dispatch({ type: 'activate', pane: pane.id, tab: id })}
            closable={canClose(state, id)}
            onClose={() => dispatch({ type: 'close', tab: id })}
            onDragStart={(event) => {
              event.dataTransfer.effectAllowed = 'move';
              event.dataTransfer.setData(TAB_TYPE, id);
              // Dropped outside the app, a tab is its address.
              event.dataTransfer.setData('text/plain', new URL(state.tabs[id].href, window.location.origin).href);
              drag.start(id);
            }}
            onDragEnd={() => {
              drag.end();
              setInsertAt(null);
            }}
          />
        ))}
        {insertAt === pane.tabs.length ? <InsertMarker /> : null}
      </div>
    </div>
  );
}

function InsertMarker() {
  return <span aria-hidden="true" className="mb-1.5 h-5 w-0.5 shrink-0 self-end rounded bg-accent" />;
}

function Tab({ tab, active, closable, dragging, insertBefore, onActivate, onClose, onDragStart, onDragEnd }) {
  const { t } = useI18n();
  const { title, icon: Icon } = useTabTitle(tab.href);

  return (
    <>
      {insertBefore ? <InsertMarker /> : null}
      <div
        data-tab
        role="presentation"
        draggable
        onDragStart={onDragStart}
        onDragEnd={onDragEnd}
        title={t('workspace.tabHint', { title })}
        className={cn(
          'group flex h-full max-w-56 shrink-0 items-center gap-1 rounded-t-md border border-b-0 pl-3 text-sm',
          closable ? 'pr-1' : 'pr-3',
          active ? 'border-slate-200 bg-white text-slate-900' : 'border-transparent text-slate-600 hover:bg-slate-50/70 hover:text-slate-900',
          dragging && 'opacity-40',
        )}
      >
        <button
          type="button"
          role="tab"
          aria-selected={active}
          onClick={onActivate}
          // Middle click closes, as it does in a browser.
          onAuxClick={(event) => {
            if (event.button !== 1 || !closable) return;
            event.preventDefault();
            onClose();
          }}
          className="flex min-w-0 items-center gap-1.5 outline-none focus-visible:underline"
        >
          <Icon aria-hidden="true" className="size-3.5 shrink-0 text-slate-400" />
          <span className="truncate">{title}</span>
        </button>
        {/* The dashboard alone has nothing to close to. */}
        {closable ? (
          <button
            type="button"
            onClick={onClose}
            aria-label={t('workspace.close', { title })}
            className={cn(
              'rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700',
              !active && 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100',
            )}
          >
            <X aria-hidden="true" className="size-3.5" />
          </button>
        ) : null}
      </div>
    </>
  );
}

/**
 * A cached record, read without fetching: a tab's title uses what its page
 * already loaded, and falls back to a generic name until then.
 */
function useCached(queryKey) {
  return useQuery({ queryKey, enabled: false }).data;
}

/** What a tab is called: the page's name, or the record it shows (Order 10042). */
export function useTabTitle(href) {
  const { t } = useI18n();
  const path = pathOf(href);
  const [, section, id] = path.split('/');
  const detail = id && !['new', 'import'].includes(id) ? id : null;

  const order = useCached(['order', section === 'orders' ? detail : null]);
  const customer = useCached(['customers', 'detail', section === 'customers' ? detail : null]);
  const product = useCached(['product', section === 'products' ? detail : null]);

  if (path === '/') return { title: t('nav.dashboard'), icon: LayoutDashboard };
  if (path === '/settings') return { title: t('nav.settings'), icon: Settings };
  if (path === '/dev/table') return { title: t('tableDemo.title'), icon: Table2 };

  if (section === 'orders') {
    if (id === 'new') return { title: t('orders.new'), icon: ShoppingCart };
    if (id === 'import') return { title: t('orderImport.title'), icon: Upload };
    if (detail) return { title: order?.number ? t('orders.detailTitle', { number: order.number }) : t('workspace.order'), icon: FileText };
    return { title: t('nav.orders'), icon: ShoppingCart };
  }
  if (section === 'customers') {
    if (id === 'new') return { title: t('customers.new'), icon: Users };
    if (detail) return { title: customer?.name ?? t('workspace.customer'), icon: Users };
    return { title: t('nav.customers'), icon: Users };
  }
  if (section === 'products') {
    if (id === 'import') return { title: t('nav.importProducts'), icon: Upload };
    if (detail) return { title: product?.sku ?? t('workspace.product'), icon: Package };
    return { title: t('nav.products'), icon: Package };
  }
  if (section === 'locations') return { title: t('nav.locations'), icon: MapPin };

  return { title: path, icon: FileText };
}

/**
 * One tab's page, under its own router, placed over its pane. Kept mounted
 * while another tab is in front, so coming back finds it scrolled where it was
 * with its dialog open.
 */
function TabView({ id, pane, active, front, box }) {
  const { state, dispatch, open, navigated, register } = useWorkspace();
  const [router] = useState(() => createTabRouter(state.tabs[id].href));

  useEffect(() => {
    register(id, router);
    return () => register(id, null);
  }, [id, router, register]);

  useEffect(() => router.history.subscribe(({ location, action }) => navigated(id, location.href, action.type)), [id, router, navigated]);

  // The workspace re-renders on every change to it — each pixel a divider is
  // dragged — and the page has no reason to follow.
  const page = useMemo(() => <RouterProvider router={router} />, [router]);

  // Ctrl-, cmd- or middle-click on a link inside a page opens it as a tab
  // here rather than as a second copy of the whole app in a browser tab.
  const openInTab = (event) => {
    const href = appLink(event.target);
    if (href === null) return;
    event.preventDefault();
    event.stopPropagation();
    open(href, { newTab: true, background: true });
  };

  return (
    <div
      hidden={!active}
      data-front-tab={front || undefined}
      style={bodyOf(box)}
      className="absolute"
      onPointerDownCapture={() => dispatch({ type: 'focus', pane })}
      onClickCapture={(event) => {
        if (event.button === 0 && (event.ctrlKey || event.metaKey)) openInTab(event);
      }}
      onAuxClickCapture={(event) => {
        if (event.button === 1) openInTab(event);
      }}
    >
      {/* A flex column, so a list page fills its pane and only its table scrolls (DataTable `fill`). */}
      <div className="flex h-full min-w-0 flex-col overflow-auto p-4 md:p-6">
        <FrontTabProvider value={front}>{page}</FrontTabProvider>
      </div>
    </div>
  );
}

/** The app address a link points at, or null for a download, the API or elsewhere. */
function appLink(target) {
  const anchor = target.closest?.('a[href]');
  if (!anchor || anchor.hasAttribute('download') || anchor.target === '_blank') return null;

  const url = new URL(anchor.href, window.location.href);
  if (url.origin !== window.location.origin || /^\/(api|health)(\/|$)/.test(url.pathname)) return null;

  return url.pathname + url.search + url.hash;
}

/** The line between the two halves of one split, which resizes them. */
function Splitter({ split, container }) {
  const { t } = useI18n();
  const { dispatch } = useWorkspace();
  const row = split.direction === 'row';
  const { box, ratio } = split;
  const at = row ? box.x + box.w * ratio : box.y + box.h * ratio;

  // A wide grip over a one-pixel line: easy to catch, quiet to look at.
  const style = row
    ? { left: `calc(${pct(at)} - 3px)`, top: pct(box.y), width: 7, height: pct(box.h) }
    : { top: `calc(${pct(at)} - 3px)`, left: pct(box.x), width: pct(box.w), height: 7 };

  const resizeTo = (event) => {
    const rect = container.current.getBoundingClientRect();
    const next = row ? ((event.clientX - rect.left) / rect.width - box.x) / box.w : ((event.clientY - rect.top) / rect.height - box.y) / box.h;
    dispatch({ type: 'resize', split: split.id, ratio: next });
  };

  const keys = row ? { ArrowLeft: -0.05, ArrowRight: 0.05 } : { ArrowUp: -0.05, ArrowDown: 0.05 };

  // The WAI-ARIA window splitter: a focusable separator the arrow keys move.
  // jsx-a11y counts every separator as static, which that pattern is not.
  return (
    // eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
    <div
      role="separator"
      // Panes side by side are divided by a vertical line.
      aria-orientation={row ? 'vertical' : 'horizontal'}
      aria-label={t('workspace.resize')}
      aria-valuemin={15}
      aria-valuemax={85}
      aria-valuenow={Math.round(ratio * 100)}
      // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex
      tabIndex={0}
      style={style}
      className={cn('group absolute z-30 flex justify-center focus-visible:outline-none', row ? 'cursor-col-resize' : 'cursor-row-resize flex-col')}
      onPointerDown={(event) => {
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
      }}
      onPointerMove={(event) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId)) resizeTo(event);
      }}
      onKeyDown={(event) => {
        const step = keys[event.key];
        if (step === undefined) return;
        event.preventDefault();
        dispatch({ type: 'resize', split: split.id, ratio: ratio + step });
      }}
    >
      <span
        aria-hidden="true"
        className={cn(
          'bg-slate-200 group-hover:bg-accent group-focus-visible:bg-accent',
          row ? 'h-full w-px group-hover:w-0.5 group-focus-visible:w-0.5' : 'h-px w-full group-hover:h-0.5 group-focus-visible:h-0.5',
        )}
      />
    </div>
  );
}
