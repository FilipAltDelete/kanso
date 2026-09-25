import { useMemo, useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Pencil, SlidersHorizontal } from 'lucide-react';
import { useAllLocations, useInventoryLevels, useMovements, useProduct } from '../../api/inventory.js';
import { Badge, Button, Card, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useAuth } from '../auth/AuthProvider.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatChange, formatQuantity, formatWeight } from '../../lib/quantity.js';
import { AdjustStockDialog } from './AdjustStockDialog.jsx';
import { ProductFormDialog } from './ProductFormDialog.jsx';
import { stockPerLocation } from './stockRows.js';

const CAN_ADJUST = ['ROLE_ADMIN', 'ROLE_OPERATOR'];

/**
 * One product: what it is, its stock at every location (zero where there is
 * none yet, so stock can be added there too), and the history of changes.
 */
export function ProductDetailPage() {
  const { productId } = useParams({ strict: false });
  const { t, locale } = useI18n();
  const { user } = useAuth();
  const canAdjust = user?.roles?.some((role) => CAN_ADJUST.includes(role)) ?? false;

  const product = useProduct(productId);
  const locations = useAllLocations();
  const levels = useInventoryLevels(productId);
  const historyView = useUrlView({ prefix: 'h.' });
  const history = useMovements(productId, historyView.view);
  const [adjusting, setAdjusting] = useState(null);
  const [editing, setEditing] = useState(false);

  const stockRows = useMemo(() => stockPerLocation(locations.data ?? [], levels.data ?? []), [levels.data, locations.data]);

  const dateTime = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }), [locale]);

  const stockColumns = useMemo(() => {
    const quantity = (key) => ({
      accessorKey: key,
      header: t(`stock.${key}`),
      meta: { align: 'end' },
      enableGlobalFilter: false,
      cell: ({ getValue }) => formatQuantity(getValue(), locale),
    });

    return [
      { id: 'code', accessorFn: (row) => row.location.code, header: t('location.code'), cell: ({ getValue }) => <span className="font-medium">{getValue()}</span> },
      { id: 'name', accessorFn: (row) => row.location.name, header: t('location.name'), sortingFn: 'locale' },
      quantity('onHand'),
      quantity('reserved'),
      quantity('available'),
      {
        accessorKey: 'updatedAt',
        header: t('stock.updatedAt'),
        enableGlobalFilter: false,
        cell: ({ getValue }) => (getValue() ? <time dateTime={getValue()}>{dateTime.format(new Date(getValue()))}</time> : ''),
      },
      ...(canAdjust
        ? [
            {
              id: 'actions',
              header: () => <span className="sr-only">{t('common.actions')}</span>,
              meta: { label: t('common.actions'), align: 'end' },
              enableSorting: false,
              enableGlobalFilter: false,
              cell: ({ row }) => (
                <Button variant="outline" size="sm" onClick={() => setAdjusting(row.original)}>
                  <SlidersHorizontal className="size-3.5" aria-hidden="true" />
                  {t('adjust.open')}
                  <span className="sr-only">{row.original.location.code}</span>
                </Button>
              ),
            },
          ]
        : []),
    ];
  }, [t, locale, dateTime, canAdjust]);

  const historyColumns = useMemo(
    () => [
      {
        accessorKey: 'occurredAt',
        header: t('history.when'),
        enableSorting: false,
        cell: ({ getValue }) => <time dateTime={getValue()}>{dateTime.format(new Date(getValue()))}</time>,
      },
      { accessorKey: 'locationCode', header: t('location.code'), enableSorting: false },
      {
        accessorKey: 'reason',
        header: t('history.reason'),
        enableSorting: false,
        cell: ({ row }) => (row.original.reason ? t(`reason.${row.original.reason}`) : t(`movementType.${row.original.type}`)),
      },
      {
        accessorKey: 'onHandChange',
        header: t('history.change'),
        enableSorting: false,
        meta: { align: 'end' },
        cell: ({ getValue }) => (
          <Badge tone={getValue() > 0 ? 'green' : getValue() < 0 ? 'amber' : 'slate'}>{formatChange(getValue(), locale)}</Badge>
        ),
      },
      {
        id: 'onHand',
        header: t('history.onHand'),
        enableSorting: false,
        meta: { align: 'end', label: t('history.onHand') },
        cell: ({ row }) => t('history.fromTo', { from: formatQuantity(row.original.onHandBefore, locale), to: formatQuantity(row.original.onHandAfter, locale) }),
      },
      { accessorKey: 'actorName', header: t('history.by'), enableSorting: false },
      {
        accessorKey: 'note',
        header: t('history.note'),
        enableSorting: false,
        cell: ({ row }) =>
          row.original.orderId ? (
            <Link to="/orders/$orderId" params={{ orderId: row.original.orderId }} className="underline-offset-2 hover:underline">
              {t('history.order', { number: row.original.orderNumber })}
            </Link>
          ) : (
            (row.original.note ?? '')
          ),
      },
    ],
    [t, locale, dateTime],
  );

  if (product.isPending) return <Spinner label={t('common.loading')} />;
  if (product.error) {
    return (
      <div className="space-y-3">
        <BackLink />
        <ErrorNotice error={product.error.status === 404 ? { message: t('product.notFound') } : product.error} />
      </div>
    );
  }

  const p = product.data;
  const facts = [
    ['product.sku', p.sku],
    ['product.barcode', p.barcode ?? '—'],
    ['product.weight', p.weightGrams === null ? '—' : formatWeight(p.weightGrams, locale)],
    ['stock.onHand', formatQuantity(p.onHand, locale)],
    ['stock.reserved', formatQuantity(p.reserved, locale)],
    ['stock.available', formatQuantity(p.available, locale)],
  ];

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <BackLink />
        <div className="flex flex-wrap items-start justify-between gap-3">
          <h1 className="text-xl font-semibold">{p.name}</h1>
          {canAdjust ? (
            <Button variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" aria-hidden="true" />
              {t('productForm.edit')}
            </Button>
          ) : null}
        </div>
      </div>

      <Card className="p-4">
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          {facts.map(([key, value]) => (
            <div key={key}>
              <dt className="text-xs text-slate-500">{t(key)}</dt>
              <dd className="font-medium tabular-nums">{value}</dd>
            </div>
          ))}
        </dl>
      </Card>

      <section aria-labelledby="stock-heading" className="space-y-2">
        <h2 id="stock-heading" className="text-base font-semibold">
          {t('productDetail.stockPerLocation')}
        </h2>
        {levels.error || locations.error ? <ErrorNotice error={levels.error ?? locations.error} /> : null}
        <DataTable
          label={t('productDetail.stockPerLocation')}
          data={stockRows}
          columns={stockColumns}
          getRowId={(row) => row.location.id}
          loading={levels.isPending || locations.isPending}
          searchable={stockRows.length > 10}
          onRowActivate={canAdjust ? setAdjusting : undefined}
          emptyMessage={t('productDetail.noLocations')}
        />
      </section>

      <section aria-labelledby="history-heading" className="space-y-2">
        <h2 id="history-heading" className="text-base font-semibold">
          {t('productDetail.history')}
        </h2>
        {history.error ? <ErrorNotice error={history.error} /> : null}
        <DataTable
          {...historyView}
          manual
          searchable={false}
          label={t('productDetail.history')}
          data={history.data?.member ?? []}
          rowCount={history.data?.totalItems ?? 0}
          loading={history.isPending}
          columns={historyColumns}
          getRowId={(movement) => movement.id}
          emptyMessage={t('productDetail.noHistory')}
        />
      </section>

      {editing ? <ProductFormDialog product={p} onClose={() => setEditing(false)} /> : null}

      {adjusting ? (
        <AdjustStockDialog
          productId={p.id}
          sku={p.sku}
          location={adjusting.location}
          // The row is rebuilt from fresh data after a refetch, so after a 409
          // the dialog shows — and submits against — the current numbers.
          stock={stockRows.find((row) => row.location.id === adjusting.location.id) ?? adjusting}
          onClose={() => setAdjusting(null)}
        />
      ) : null}
    </div>
  );
}

function BackLink() {
  const { t } = useI18n();

  return (
    <Link to="/products" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
      <ArrowLeft className="size-4" aria-hidden="true" />
      {t('products.back')}
    </Link>
  );
}
