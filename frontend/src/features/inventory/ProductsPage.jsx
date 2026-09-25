import { useMemo, useState } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { FileUp, Plus } from 'lucide-react';
import { useProducts } from '../../api/inventory.js';
import { Button, ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity, formatWeight } from '../../lib/quantity.js';
import { useCanEditCatalog } from './catalogForm.js';
import { ProductFormDialog } from './ProductFormDialog.jsx';

/** Every product with its stock summed over all locations. Sorting, search and paging happen on the server. */
export function ProductsPage() {
  const { t, locale } = useI18n();
  const navigate = useNavigate();
  const urlView = useUrlView({ defaults: { sorting: [{ id: 'sku', desc: false }] } });
  const products = useProducts(urlView.view);
  const canEdit = useCanEditCatalog();
  const [creating, setCreating] = useState(false);

  const columns = useMemo(() => {
    const quantity = (key) => ({
      accessorKey: key,
      header: t(`stock.${key}`),
      enableSorting: false,
      meta: { align: 'end' },
      cell: ({ getValue }) => formatQuantity(getValue(), locale),
    });

    return [
      {
        accessorKey: 'sku',
        header: t('product.sku'),
        cell: ({ row }) => (
          <Link to="/products/$productId" params={{ productId: row.original.id }} className="font-medium underline-offset-2 hover:underline">
            {row.original.sku}
          </Link>
        ),
      },
      { accessorKey: 'name', header: t('product.name') },
      { accessorKey: 'barcode', header: t('product.barcode'), enableSorting: false, cell: ({ getValue }) => getValue() ?? '' },
      {
        accessorKey: 'weightGrams',
        header: t('product.weight'),
        enableSorting: false,
        meta: { align: 'end' },
        cell: ({ getValue }) => formatWeight(getValue(), locale),
      },
      quantity('onHand'),
      quantity('reserved'),
      quantity('available'),
    ];
  }, [t, locale]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{t('products.title')}</h1>
          <p className="text-sm text-slate-500">{t('products.subtitle')}</p>
        </div>
        {canEdit ? (
          <div className="flex gap-2">
            <Link
              to="/products/import"
              className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            >
              <FileUp className="size-4" aria-hidden="true" />
              {t('import.open')}
            </Link>
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden="true" />
              {t('productForm.open')}
            </Button>
          </div>
        ) : null}
      </div>
      {products.error ? <ErrorNotice error={products.error} /> : null}
      <DataTable
        {...urlView}
        manual
        label={t('products.title')}
        data={products.data?.member ?? []}
        rowCount={products.data?.totalItems ?? 0}
        loading={products.isPending}
        columns={columns}
        getRowId={(product) => product.id}
        onRowActivate={(product) => navigate({ to: '/products/$productId', params: { productId: product.id } })}
        emptyMessage={t(canEdit ? 'products.empty' : 'products.emptyViewer')}
      />
      {creating ? (
        <ProductFormDialog onClose={() => setCreating(false)} onSaved={(product) => navigate({ to: '/products/$productId', params: { productId: product.id } })} />
      ) : null}
    </div>
  );
}
