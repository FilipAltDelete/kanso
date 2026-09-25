import { X } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { Button } from '../primitives.jsx';

/**
 * Shown while rows are selected. Each action gets the selected ids, the rows
 * behind them that are loaded (with server paging, rows on other pages are
 * known only by id), and a way to clear the selection when it is done.
 */
export function BulkActionBar({ actions, ids, rows, onClear }) {
  const { t, locale } = useI18n();
  const count = new Intl.NumberFormat(locale).format(ids.length);

  return (
    <div
      role="region"
      aria-label={t('table.bulkActions')}
      className="flex flex-wrap items-center gap-2 rounded-md border border-slate-300 bg-slate-100 px-3 py-2"
    >
      <span className="text-sm font-medium text-slate-900">{t('table.selected', { count })}</span>
      <div className="flex flex-wrap gap-2">
        {actions.map(({ id, label, icon: Icon, variant = 'outline', onClick }) => (
          <Button key={id} size="sm" variant={variant} onClick={() => onClick({ ids, rows, clear: onClear })}>
            {Icon ? <Icon className="size-3.5" aria-hidden="true" /> : null}
            {label}
          </Button>
        ))}
      </div>
      <Button size="sm" variant="ghost" className="ml-auto" onClick={onClear}>
        <X className="size-3.5" aria-hidden="true" />
        {t('table.clearSelection')}
      </Button>
    </div>
  );
}
