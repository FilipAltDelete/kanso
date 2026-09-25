import { Link } from '@tanstack/react-router';
import { useDashboard } from '../../api/dashboard.js';
import { AWAITING_FULFILLMENT, ORDER_STATUSES } from '../../api/orders.js';
import { Card, EmptyState, ErrorNotice } from '../../components/ui/primitives.jsx';
import { cn } from '../../lib/utils.js';
import { useI18n } from '../../lib/i18n.jsx';
import { StatusBadge } from '../orders/shared.jsx';

const cardLink = 'block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900';

/**
 * The operator's day at a glance. Every number is counted by the API
 * (GET /api/dashboard) for the browser's calendar day, refreshed every 30
 * seconds, and each one opens the list it counts.
 */
export function DashboardPage() {
  const { t, locale } = useI18n();
  const dashboard = useDashboard();
  const data = dashboard.data;
  const number = new Intl.NumberFormat(locale);
  const time = new Intl.DateTimeFormat(locale, { timeStyle: 'short' });

  const kpis = [
    { key: 'kpi.ordersToday', value: data?.ordersToday, to: '/orders', search: data ? { 'f.placedAt': `${data.date}..${data.date}` } : undefined },
    { key: 'kpi.awaitingFulfillment', value: data?.awaitingFulfillment, to: '/orders', search: { 'f.status': AWAITING_FULFILLMENT.join(',') } },
    { key: 'kpi.shippedToday', value: data?.shippedToday, to: '/orders', search: { 'f.status': 'shipped' } },
    { key: 'kpi.stockOuts', value: data?.stockOuts.count, to: '/', hash: 'stock-outs' },
  ];
  const noOrders = data ? ORDER_STATUSES.every((status) => data.ordersByStatus[status] === 0) : false;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">{t('dashboard.title')}</h1>
          <p className="text-sm text-slate-500">{t('dashboard.subtitle')}</p>
        </div>
        {data ? (
          <p className="text-xs text-slate-500">
            {t('dashboard.updated', { time: time.format(new Date(data.generatedAt)) })}
          </p>
        ) : null}
      </div>

      {dashboard.error ? <ErrorNotice error={dashboard.error} /> : null}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpis.map(({ key, value, to, search, hash }) => (
          <Link key={key} to={to} search={search} hash={hash} className={cardLink}>
            <Card className="h-full p-4 hover:border-slate-300 hover:bg-slate-50">
              <p className="text-sm text-slate-500">{t(key)}</p>
              <p className={cn('mt-1 text-2xl font-semibold tabular-nums', value === undefined ? 'text-slate-400' : 'text-slate-900')}>
                {value === undefined ? '—' : number.format(value)}
              </p>
            </Card>
          </Link>
        ))}
      </div>

      {noOrders ? <EmptyState title={t('dashboard.emptyTitle')}>{t('dashboard.emptyBody')}</EmptyState> : null}

      {data && !noOrders ? (
        <section aria-labelledby="orders-by-status">
          <h2 id="orders-by-status" className="text-sm font-semibold text-slate-900">
            {t('dashboard.ordersByStatus')}
          </h2>
          <ul className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            {ORDER_STATUSES.map((status) => (
              <li key={status}>
                <Link
                  to="/orders"
                  search={{ 'f.status': status }}
                  className="flex items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                >
                  <StatusBadge status={status} />
                  <span className="text-sm font-medium tabular-nums">{number.format(data.ordersByStatus[status])}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {data ? <StockOuts stockOuts={data.stockOuts} number={number} /> : null}
    </div>
  );
}

function StockOuts({ stockOuts, number }) {
  const { t } = useI18n();

  return (
    <section id="stock-outs" aria-labelledby="stock-outs-title" className="scroll-mt-4">
      <h2 id="stock-outs-title" className="text-sm font-semibold text-slate-900">
        {t('dashboard.stockOuts')}
      </h2>
      {stockOuts.count === 0 ? (
        <p className="mt-2 text-sm text-slate-600">{t('dashboard.noStockOuts')}</p>
      ) : (
        <Card className="mt-2 overflow-x-auto">
          <table className="w-full text-sm">
            <caption className="sr-only">{t('dashboard.stockOuts')}</caption>
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-600">
                <th scope="col" className="px-4 py-2 font-medium">{t('order.sku')}</th>
                <th scope="col" className="px-4 py-2 font-medium">{t('order.productName')}</th>
                <th scope="col" className="px-4 py-2 font-medium">{t('dashboard.location')}</th>
                <th scope="col" className="px-4 py-2 text-right font-medium">{t('dashboard.onHand')}</th>
                <th scope="col" className="px-4 py-2 text-right font-medium">{t('dashboard.reserved')}</th>
              </tr>
            </thead>
            <tbody>
              {stockOuts.items.map((item) => (
                <tr key={`${item.productId}-${item.locationId}`} className="border-b border-slate-100">
                  <td className="px-4 py-2 font-mono text-xs">{item.sku}</td>
                  <td className="px-4 py-2">
                    <Link to="/products/$productId" params={{ productId: item.productId }} className="hover:underline">
                      {item.productName}
                    </Link>
                  </td>
                  <td className="px-4 py-2">{item.locationName}</td>
                  <td className="px-4 py-2 text-right tabular-nums">{number.format(item.onHand)}</td>
                  <td className="px-4 py-2 text-right tabular-nums">{number.format(item.reserved)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {stockOuts.count > stockOuts.items.length ? (
            <p className="px-4 py-2 text-xs text-slate-500">{t('dashboard.moreStockOuts', { count: number.format(stockOuts.count - stockOuts.items.length) })}</p>
          ) : null}
        </Card>
      )}
    </section>
  );
}
