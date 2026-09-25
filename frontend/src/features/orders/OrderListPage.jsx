import { useMemo } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { FileUp, Plus } from 'lucide-react';
import { AWAITING_FULFILLMENT, ORDER_STATUSES, useChannels, useOrders } from '../../api/orders.js';
import { ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatMoney } from '../../lib/money.js';
import { StatusBadge, useCanOperate, useDateTime } from './shared.jsx';

const DEFAULTS = { sorting: [{ id: 'placedAt', desc: true }] };

/**
 * The order list. Filtering, sorting and paging happen on the server; the
 * view is in the URL, so a filtered list can be bookmarked or sent to a
 * colleague. Column ids are the API's sort fields.
 */
export function OrderListPage() {
  const { t, locale } = useI18n();
  const navigate = useNavigate();
  const canOperate = useCanOperate();
  const dateTime = useDateTime();
  const urlView = useUrlView({ defaults: DEFAULTS });
  const orders = useOrders(urlView.view);
  const channels = useChannels();

  const columns = useMemo(
    () => [
      {
        id: 'number',
        accessorKey: 'number',
        header: t('order.number'),
        cell: ({ row }) => (
          <Link to="/orders/$orderId" params={{ orderId: row.original.id }} className="font-medium text-slate-900 underline-offset-2 hover:underline">
            {row.original.number}
          </Link>
        ),
      },
      {
        id: 'placedAt',
        accessorKey: 'placedAt',
        header: t('order.placedAt'),
        meta: { filter: { type: 'dateRange' } },
        cell: ({ getValue }) => <time dateTime={getValue()}>{dateTime(getValue())}</time>,
      },
      { id: 'customerName', accessorFn: (order) => order.customer.name, header: t('order.customer') },
      {
        id: 'channel',
        accessorFn: (order) => order.channel.code,
        header: t('order.channel'),
        enableSorting: false,
        meta: { filter: { options: (channels.data ?? []).map((channel) => ({ value: channel.code, label: channel.name })) } },
        cell: ({ row }) => row.original.channel.name,
      },
      {
        id: 'status',
        accessorKey: 'status',
        header: t('order.status'),
        meta: {
          filter: {
            options: [
              // Several statuses at once: the API takes a comma-separated list.
              { value: AWAITING_FULFILLMENT.join(','), label: t('kpi.awaitingFulfillment') },
              ...ORDER_STATUSES.map((status) => ({ value: status, label: t(`orderStatus.${status}`) })),
            ],
          },
        },
        cell: ({ getValue }) => <StatusBadge status={getValue()} />,
      },
      { id: 'lineCount', accessorKey: 'lineCount', header: t('order.lines'), enableSorting: false, meta: { align: 'end' } },
      {
        id: 'total',
        accessorKey: 'total',
        header: t('order.total'),
        meta: { align: 'end' },
        cell: ({ row }) => formatMoney(row.original.total, row.original.currency, locale),
      },
    ],
    [t, locale, dateTime, channels.data],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{t('orders.title')}</h1>
          <p className="text-sm text-slate-500">{t('orders.subtitle')}</p>
        </div>
        {canOperate ? (
          <div className="flex gap-2">
            <Link
              to="/orders/import"
              className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            >
              <FileUp className="size-4" aria-hidden="true" />
              {t('orderImport.open')}
            </Link>
            <Link
              to="/orders/new"
              className="inline-flex h-9 items-center gap-2 rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            >
              <Plus className="size-4" aria-hidden="true" />
              {t('orders.new')}
            </Link>
          </div>
        ) : null}
      </div>

      {orders.error ? <ErrorNotice error={orders.error} /> : null}

      <DataTable
        {...urlView}
        manual
        label={t('orders.title')}
        data={orders.data?.member ?? []}
        rowCount={orders.data?.totalItems ?? 0}
        loading={orders.isPending}
        columns={columns}
        getRowId={(order) => order.id}
        getRowLabel={(order) => order.number}
        onRowActivate={(order) => navigate({ to: '/orders/$orderId', params: { orderId: order.id } })}
        emptyMessage={t('orders.empty')}
      />
    </div>
  );
}
