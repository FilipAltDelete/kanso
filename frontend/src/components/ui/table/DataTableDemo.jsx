import { useMemo, useState } from 'react';
import { Download, Printer } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { Badge } from '../primitives.jsx';
import { DataTable } from './DataTable.jsx';
import { CHANNELS, demoOrders, ORDER_STATUSES } from './demoOrders.js';
import { useUrlView } from './useUrlView.js';

const STATUS_TONES = { delivered: 'green', shipped: 'green', on_hold: 'amber', cancelled: 'amber' };

/** Minor units to a display string; the float exists only for Intl. */
function formatMoney({ amount, currency }, locale) {
  const format = new Intl.NumberFormat(locale, { style: 'currency', currency });

  return format.format(amount / 10 ** format.resolvedOptions().maximumFractionDigits);
}

/**
 * A dev-only page (`/dev/table`) that shows the list component with the
 * shape of a Phase 1 order list, until real endpoints exist.
 */
export function DataTableDemo() {
  const { t, locale } = useI18n();
  const urlView = useUrlView({ defaults: { sorting: [{ id: 'createdAt', desc: true }] } });
  const data = useMemo(() => demoOrders(), []);
  const [notice, setNotice] = useState('');

  const columns = useMemo(() => {
    const dateTime = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });

    return [
      { accessorKey: 'number', header: t('order.number'), cell: ({ getValue }) => <span className="font-medium">{getValue()}</span> },
      { accessorKey: 'customer', header: t('order.customer'), sortingFn: 'locale' },
      {
        accessorKey: 'channel',
        header: t('order.channel'),
        meta: { filter: { options: CHANNELS.map((channel) => ({ value: channel, label: channel })) } },
      },
      {
        accessorKey: 'status',
        header: t('order.status'),
        enableGlobalFilter: false,
        meta: { filter: { options: ORDER_STATUSES.map((status) => ({ value: status, label: t(`orderStatus.${status}`) })) } },
        cell: ({ getValue }) => <Badge tone={STATUS_TONES[getValue()]}>{t(`orderStatus.${getValue()}`)}</Badge>,
      },
      { accessorKey: 'lines', header: t('order.lines'), enableGlobalFilter: false, meta: { align: 'end' } },
      {
        id: 'total',
        accessorFn: (order) => order.total.amount,
        header: t('order.total'),
        enableGlobalFilter: false,
        meta: { align: 'end' },
        cell: ({ row }) => formatMoney(row.original.total, locale),
      },
      {
        accessorKey: 'createdAt',
        header: t('order.createdAt'),
        enableGlobalFilter: false,
        cell: ({ getValue }) => <time dateTime={getValue()}>{dateTime.format(new Date(getValue()))}</time>,
      },
    ];
  }, [t, locale]);

  const bulkActions = useMemo(() => {
    const done = (action) => ({ ids, clear }) => {
      setNotice(t('tableDemo.actionDone', { action, count: new Intl.NumberFormat(locale).format(ids.length) }));
      clear();
    };

    return [
      { id: 'pick-lists', label: t('tableDemo.printPickLists'), icon: Printer, onClick: done(t('tableDemo.printPickLists')) },
      { id: 'export', label: t('tableDemo.export'), icon: Download, onClick: done(t('tableDemo.export')) },
    ];
  }, [t, locale]);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">{t('tableDemo.title')}</h1>
        <p className="text-sm text-slate-500">{t('tableDemo.subtitle')}</p>
      </div>
      <p role="status" className="min-h-5 text-sm text-slate-700">
        {notice}
      </p>
      <DataTable
        {...urlView}
        fill
        label={t('tableDemo.caption')}
        data={data}
        columns={columns}
        getRowId={(order) => order.id}
        getRowLabel={(order) => order.number}
        bulkActions={bulkActions}
        onRowActivate={(order) => setNotice(t('tableDemo.opened', { number: order.number }))}
      />
    </div>
  );
}
