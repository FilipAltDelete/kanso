import { useRef, useState } from 'react';
import { Check, Keyboard } from 'lucide-react';
import { Button, Checkbox } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { useShortcutSettings } from '../../lib/ShortcutsProvider.jsx';
import { saveTheme, savedTheme, THEMES } from '../../lib/theme.js';
import { cn } from '../../lib/utils.js';

/**
 * This person's settings. A theme applies and is remembered as soon as it is
 * picked, like the language. The theme cards are a radio group: arrow keys
 * move between them.
 */
export function SettingsPage() {
  const { t } = useI18n();
  const [theme, setTheme] = useState(savedTheme);
  const cards = useRef([]);

  const choose = (id) => {
    setTheme(id);
    saveTheme(id);
  };

  function onKeyDown(event) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    const index = THEMES.findIndex((candidate) => candidate.id === theme);
    const next = (index + step + THEMES.length) % THEMES.length;
    choose(THEMES[next].id);
    cards.current[next]?.focus();
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">{t('settings.title')}</h1>
        <p className="text-sm text-slate-500">{t('settings.subtitle')}</p>
      </div>

      <section aria-labelledby="theme-heading">
        <h2 id="theme-heading" className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
          {t('settings.theme')}
        </h2>
        <div role="radiogroup" aria-labelledby="theme-heading" className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
          {THEMES.map((option, index) => {
            const selected = option.id === theme;

            return (
              <button
                key={option.id}
                ref={(element) => {
                  cards.current[index] = element;
                }}
                type="button"
                role="radio"
                aria-checked={selected}
                tabIndex={selected ? 0 : -1}
                onClick={() => choose(option.id)}
                onKeyDown={onKeyDown}
                // Each card is drawn in its own theme's colours, whatever the
                // app is showing, so the choice is visible before it is made.
                style={{
                  backgroundColor: option.preview.background,
                  color: option.preview.foreground,
                  borderColor: selected ? option.dots[1] : option.preview.border,
                }}
                className={cn(
                  'relative rounded-lg border-2 p-3 text-left transition',
                  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500',
                  selected ? 'shadow-md' : 'opacity-90 hover:opacity-100',
                )}
              >
                <span className="block text-sm font-medium">{option.label}</span>
                <span className="mt-3 flex gap-1.5" aria-hidden="true">
                  {option.dots.map((colour) => (
                    <span key={colour} className="size-3 rounded-full" style={{ backgroundColor: colour }} />
                  ))}
                </span>
                {selected ? (
                  <Check aria-hidden="true" className="absolute right-2 top-2 size-4" style={{ color: option.dots[1] }} />
                ) : null}
              </button>
            );
          })}
        </div>
        <p className="mt-3 text-xs text-slate-500">{t('settings.rememberedHere')}</p>
      </section>

      <ShortcutSettings />
    </div>
  );
}

/**
 * Keyboard shortcuts: on or off (saved in this browser), and the list of them.
 * The list also opens with "?" on any page.
 */
function ShortcutSettings() {
  const { t } = useI18n();
  const { enabled, setEnabled, openHelp } = useShortcutSettings();

  if (!setEnabled) return null;

  return (
    <section aria-labelledby="shortcuts-heading" className="space-y-3">
      <h2 id="shortcuts-heading" className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
        {t('shortcuts.title')}
      </h2>
      <div className="space-y-1">
        <label className="flex items-center gap-2 text-sm font-medium text-slate-900">
          <Checkbox checked={enabled} onChange={(event) => setEnabled(event.target.checked)} aria-describedby="settings-shortcuts-hint" />
          {t('shortcuts.enabled')}
        </label>
        <p id="settings-shortcuts-hint" className="text-xs text-slate-500">
          {t('shortcuts.enabledHint')}
        </p>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="outline" size="sm" onClick={openHelp} aria-keyshortcuts="?">
          <Keyboard className="size-4" aria-hidden="true" />
          {t('shortcuts.help')}
        </Button>
        <span className="text-xs text-slate-500">{t('settings.shortcutsAnywhere')}</span>
      </div>
    </section>
  );
}
