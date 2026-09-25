import { Select } from '../components/ui/primitives.jsx';
import { locales, useI18n } from '../lib/i18n.jsx';

export function LanguageSelect() {
  const { locale, setLocale, t } = useI18n();

  return (
    <Select aria-label={t('language.label')} className="h-8 text-xs" value={locale} onChange={(event) => setLocale(event.target.value)}>
      {locales.map(({ code, label }) => (
        <option key={code} value={code}>
          {label}
        </option>
      ))}
    </Select>
  );
}
