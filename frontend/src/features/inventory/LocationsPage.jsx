import { useMemo, useState } from 'react';
import { Pencil, Plus } from 'lucide-react';
import { useLocations } from '../../api/inventory.js';
import { Button, ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { useI18n } from '../../lib/i18n.jsx';
import { useCanEditCatalog } from './catalogForm.js';
import { LocationFormDialog } from './LocationFormDialog.jsx';

/**
 * Warehouses and stores. Sorting, search and paging happen on the server.
 * Operators create locations here and edit one from its row (Enter on a
 * focused row does the same).
 */
export function LocationsPage() {
  const { t, locale } = useI18n();
  const urlView = useUrlView({ defaults: { sorting: [{ id: 'code', desc: false }] } });
  const locations = useLocations(urlView.view);
  const canEdit = useCanEditCatalog();
  // `null` is closed, 'new' is creating, anything else is the location being edited.
  const [open, setOpen] = useState(null);
  // The row as last fetched, so a refetch after a 409 reaches the open form.
  const editing = open && open !== 'new' ? (locations.data?.member.find((location) => location.id === open.id) ?? open) : null;

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
      ...(canEdit
        ? [
            {
              id: 'actions',
              header: () => <span className="sr-only">{t('common.actions')}</span>,
              meta: { label: t('common.actions'), align: 'end' },
              enableSorting: false,
              cell: ({ row }) => (
                <Button variant="outline" size="sm" onClick={() => setOpen(row.original)}>
                  <Pencil className="size-3.5" aria-hidden="true" />
                  {t('locationForm.edit')}
                  <span className="sr-only">{row.original.code}</span>
                </Button>
              ),
            },
          ]
        : []),
    ];
  }, [t, locale, canEdit]);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{t('locations.title')}</h1>
          <p className="text-sm text-slate-500">{t('locations.subtitle')}</p>
        </div>
        {canEdit ? (
          <Button onClick={() => setOpen('new')}>
            <Plus className="size-4" aria-hidden="true" />
            {t('locationForm.open')}
          </Button>
        ) : null}
      </div>
      {locations.error ? <ErrorNotice error={locations.error} /> : null}
      <DataTable
        {...urlView}
        fill
        manual
        label={t('locations.title')}
        data={locations.data?.member ?? []}
        rowCount={locations.data?.totalItems ?? 0}
        loading={locations.isPending}
        columns={columns}
        getRowId={(location) => location.id}
        onRowActivate={canEdit ? setOpen : undefined}
        emptyMessage={t(canEdit ? 'locations.empty' : 'locations.emptyViewer')}
      />
      {open === 'new' ? <LocationFormDialog onClose={() => setOpen(null)} /> : null}
      {editing ? <LocationFormDialog key={editing.id} location={editing} onClose={() => setOpen(null)} /> : null}
    </div>
  );
}
