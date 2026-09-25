import { useMemo } from 'react';
import { useLocations } from '../../api/inventory.js';
import { ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';

/** Warehouses and stores. Sorting, search and paging happen on the server. */
export function LocationsPage() {
  const { t, locale } = useI18n();
  const urlView = useUrlView({ defaults: { sorting: [{ id: 'code', desc: false }] } });
  const locations = useLocations(urlView.view);

  const columns = useMemo(() => {
    const countries = new Intl.DisplayNames([locale], { type: 'region' });

    return [
      { accessorKey: 'code', header: t('location.code'), cell: ({ getValue }) => <span className="font-medium">{getValue()}</span> },
      { accessorKey: 'name', header: t('location.name') },
      {
        id: 'address',
        header: t('location.address'),
        enableSorting: false,
        cell: ({ row }) => [row.original.addressLine1, row.original.addressLine2, [row.original.postalCode, row.original.city].filter(Boolean).join(' ')].filter(Boolean).join(', '),
      },
      { accessorKey: 'city', header: t('location.city'), cell: ({ getValue }) => getValue() ?? '' },
      {
        accessorKey: 'countryCode',
        header: t('location.country'),
        enableSorting: false,
        cell: ({ getValue }) => (getValue() ? countries.of(getValue()) : ''),
      },
    ];
  }, [t, locale]);

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold">{t('locations.title')}</h1>
        <p className="text-sm text-slate-500">{t('locations.subtitle')}</p>
      </div>
      {locations.error ? <ErrorNotice error={locations.error} /> : null}
      <DataTable
        {...urlView}
        manual
        label={t('locations.title')}
        data={locations.data?.member ?? []}
        rowCount={locations.data?.totalItems ?? 0}
        loading={locations.isPending}
        columns={columns}
        getRowId={(location) => location.id}
        emptyMessage={t('locations.empty')}
      />
    </div>
  );
}
