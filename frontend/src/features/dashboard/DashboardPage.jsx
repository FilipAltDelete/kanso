import { Card, EmptyState } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';

const kpis = ['kpi.ordersToday', 'kpi.awaitingFulfillment', 'kpi.shippedToday', 'kpi.lowStock'];

/** Phase 0: the shell of the operator's home screen. The numbers arrive with orders in Phase 1. */
export function DashboardPage() {
  const { t } = useI18n();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">{t('dashboard.title')}</h1>
        <p className="text-sm text-slate-500">{t('dashboard.subtitle')}</p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpis.map((key) => (
          <Card key={key} className="p-4">
            <p className="text-sm text-slate-500">{t(key)}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-400">—</p>
          </Card>
        ))}
      </div>

      <EmptyState title={t('dashboard.emptyTitle')}>{t('dashboard.emptyBody')}</EmptyState>
    </div>
  );
}
