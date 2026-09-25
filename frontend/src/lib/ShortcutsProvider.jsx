import { createContext, Fragment, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Button, Checkbox, Dialog } from '../components/ui/primitives.jsx';
import { useI18n } from './i18n.jsx';
import { createMatcher, isShortcutEvent, loadShortcutsEnabled, saveShortcutsEnabled, SHORTCUT_GROUPS, SHORTCUTS } from './shortcuts.js';

const ShortcutsContext = createContext(null);

/**
 * Listens for the app's keyboard shortcuts (see shortcuts.js) and owns the
 * help dialog. Pages say what a shortcut does with `useShortcuts`; one
 * listener on the document looks up the newest handler for what was typed.
 */
export function ShortcutsProvider({ children }) {
  // id -> handler refs, newest last: a page's handler wins while it is mounted.
  const handlers = useRef(new Map());
  const [enabled, setEnabledState] = useState(loadShortcutsEnabled);
  // The shortcut ids with a handler when the help opened, or null while it is closed.
  const [helpFor, setHelpFor] = useState(null);

  const openHelp = useCallback(() => setHelpFor(new Set(handlers.current.keys())), []);

  const register = useCallback((ids, ref) => {
    const entries = ids.map((id) => {
      const entry = { current: (event) => ref.current[id]?.(event) };
      handlers.current.set(id, [...(handlers.current.get(id) ?? []), entry]);
      return [id, entry];
    });

    return () => {
      for (const [id, entry] of entries) {
        const rest = (handlers.current.get(id) ?? []).filter((item) => item !== entry);
        if (rest.length > 0) handlers.current.set(id, rest);
        else handlers.current.delete(id);
      }
    };
  }, []);

  useEffect(() => register(['help'], { current: { help: openHelp } }), [register, openHelp]);

  useEffect(() => {
    if (!enabled) return undefined;
    const matcher = createMatcher();

    function onKeyDown(event) {
      if (!isShortcutEvent(event)) return;
      const active = SHORTCUTS.filter((shortcut) => !shortcut.builtIn && handlers.current.has(shortcut.id));
      const shortcut = matcher.feed(event.key, active);
      if (!shortcut) return;
      const handler = handlers.current.get(shortcut.id).at(-1);
      // A handler that returns false had nothing to do: the key is left to the browser.
      if (handler.current(event) !== false) event.preventDefault();
    }
    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [enabled]);

  const setEnabled = useCallback((next) => {
    saveShortcutsEnabled(next);
    setEnabledState(next);
  }, []);

  const value = useMemo(() => ({ register, openHelp, enabled }), [register, openHelp, enabled]);

  return (
    <ShortcutsContext.Provider value={value}>
      {children}
      {helpFor ? <ShortcutHelpDialog registered={helpFor} enabled={enabled} onEnabledChange={setEnabled} onClose={() => setHelpFor(null)} /> : null}
    </ShortcutsContext.Provider>
  );
}

/**
 * Says what shortcuts do on this page, as `{ [id]: handler }` with ids from
 * SHORTCUTS; a missing or undefined handler leaves that shortcut off. A
 * handler that returns `false` did nothing, and the key goes to the browser.
 * Outside a ShortcutsProvider it does nothing.
 */
export function useShortcuts(map) {
  const context = useContext(ShortcutsContext);
  const latest = useRef(map);
  useLayoutEffect(() => {
    latest.current = map;
  });
  const ids = Object.keys(map)
    .filter((id) => typeof map[id] === 'function')
    .sort()
    .join(' ');
  const register = context?.register;

  useEffect(() => {
    if (!register || !ids) return undefined;

    return register(ids.split(' '), latest);
  }, [register, ids]);
}

/** Opens the shortcut list; for a button that makes the shortcuts discoverable. */
export function useShortcutHelp() {
  return useContext(ShortcutsContext)?.openHelp;
}

function ShortcutHelpDialog({ registered, enabled, onEnabledChange, onClose }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  // Everywhere and the tables always; a page's own section while it is the page on screen.
  const groups = SHORTCUT_GROUPS.map((group) => ({
    group,
    shortcuts: SHORTCUTS.filter((shortcut) => shortcut.group === group && (group === 'global' || group === 'table' || registered.has(shortcut.id))),
  })).filter(({ shortcuts }) => shortcuts.length > 0);

  return (
    <Dialog dialogRef={dialogRef} title={t('shortcuts.title')} description={t('shortcuts.description')} onClose={onClose} className="max-w-lg">
      <div className="max-h-[60vh] space-y-4 overflow-y-auto">
        {groups.map(({ group, shortcuts }) => (
          <section key={group} aria-labelledby={`shortcuts-${group}`}>
            <h3 id={`shortcuts-${group}`} className="text-sm font-semibold text-slate-900">
              {t(`shortcuts.group.${group}`)}
            </h3>
            <dl className="mt-1 divide-y divide-slate-100 text-sm">
              {shortcuts.map((shortcut) => (
                <div key={shortcut.id} className="flex items-center justify-between gap-4 py-1.5">
                  <dt className="text-slate-700">{t(shortcut.labelKey)}</dt>
                  <dd className="shrink-0">
                    <Keys keys={shortcut.keys} />
                  </dd>
                </div>
              ))}
            </dl>
          </section>
        ))}
      </div>

      <div className="space-y-1 border-t border-slate-200 pt-3">
        <label className="flex items-center gap-2 text-sm font-medium text-slate-900">
          <Checkbox checked={enabled} onChange={(event) => onEnabledChange(event.target.checked)} aria-describedby="shortcuts-enabled-hint" />
          {t('shortcuts.enabled')}
        </label>
        <p id="shortcuts-enabled-hint" className="text-xs text-slate-500">
          {t('shortcuts.enabledHint')}
        </p>
      </div>

      <div className="flex justify-end">
        <Button variant="outline" onClick={() => dialogRef.current?.close()}>
          {t('shortcuts.close')}
        </Button>
      </div>
    </Dialog>
  );
}

const KEY_NAMES = { ' ': 'shortcuts.key.space', Arrows: 'shortcuts.key.arrows', Control: 'shortcuts.key.control', Escape: 'shortcuts.key.escape', Enter: 'shortcuts.key.enter' };

/** "g then o", "Ctrl + a": each key in a <kbd>. */
function Keys({ keys }) {
  const { t } = useI18n();
  const name = (key) => (KEY_NAMES[key] ? t(KEY_NAMES[key]) : key);

  return (
    <span className="inline-flex items-center gap-1 text-xs text-slate-500">
      {keys.map((step, index) => (
        <Fragment key={step}>
          {index > 0 ? <span>{t('shortcuts.then')}</span> : null}
          {step.split('+').map((key, part) => (
            <Fragment key={key}>
              {part > 0 ? <span aria-hidden="true">+</span> : null}
              <kbd className="min-w-6 rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 text-center font-mono text-xs text-slate-800">{name(key)}</kbd>
            </Fragment>
          ))}
        </Fragment>
      ))}
    </span>
  );
}
