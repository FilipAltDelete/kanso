import { useState } from 'react';
import { Select } from '../components/ui/primitives.jsx';
import { locales, useI18n } from '../lib/i18n.jsx';

/**
 * The language, switched once its catalog has downloaded (lib/i18n.jsx):
 * until then the page stays in the one it was in, and if the download fails
 * it says so and stays there.
 */
export function LanguageSelect() {
  const { locale, setLocale, t } = useI18n();
  const [choice, setChoice] = useState(null);
  const [failed, setFailed] = useState(false);

  function choose(next) {
    setChoice(next);
    setFailed(false);
    setLocale(next).then(
      () => setChoice(null),
      () => {
        setChoice(null);
        setFailed(true);
      },
    );
  }

  return (
    <div>
      <Select aria-label={t('language.label')} className="h-8 text-xs" value={choice ?? locale} onChange={(event) => choose(event.target.value)}>
        {locales.map(({ code, label }) => (
          <option key={code} value={code}>
            {label}
          </option>
        ))}
      </Select>
      {failed ? (
        <p role="alert" className="mt-1 text-xs text-red-700">
          {t('language.loadFailed')}
        </p>
      ) : null}
    </div>
  );
}
