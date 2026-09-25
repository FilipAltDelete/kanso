import { useEffect, useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { Plus } from 'lucide-react';
import { customers } from '../../api/customers.js';
import { ErrorNotice } from '../../components/ui/primitives.jsx';
import { DataTable, useUrlView } from '../../components/ui/table/index.js';
import { isTyping } from '../../components/ui/table/keyboard.js';
import { countryName } from '../../lib/countries.js';
import { useI18n } from '../../lib/i18n.jsx';
import { useDebouncedValue } from '../../lib/useDebouncedValue.js';
import { useCanEditCustomers } from './permissions.js';

const DEFAULT_SORT = [{ id: 'createdAt', desc: true }];

/** The default shipping address, else the first address: where the customer is, for the list. */
function primaryAddress(customer) {
  const shipping = customer.addresses.filter((address) => address.type === 'shipping');

  return shipping.find((address) => address.isDefault) ?? shipping[0] ?? customer.addresses[0] ?? null;
}

/**
 * The customer list: searched, sorted and paged by the API, with the view in
 * the URL. `N` opens a new customer for operators.
 */
export function CustomersPage() {
  const { t, locale } = useI18n();
  const navigate = useNavigate();
  const canEdit = useCanEditCustomers();
  const urlView = useUrlView({ defaults: { sorting: DEFAULT_SORT } });
  const { view } = urlView;
  const q = useDebouncedValue(view.globalFilter.trim());

  const params = { q, sorting: view.sorting, page: view.pageIndex + 1, pageSize: view.pageSize };
  const list = useQuery({
    queryKey: ['customers', 'list', params],
    queryFn: ({ signal }) => customers.list(params, signal),
    placeholderData: keepPreviousData,
  });

  useEffect(() => {
    if (!canEdit) return undefined;

    function onKeyDown(event) {
      if (event.key.toLowerCase() !== 'n' || event.ctrlKey || event.metaKey || event.altKey) return;
      if (event.target instanceof Element && isTyping(event.target)) return;
      event.preventDefault();
      navigate({ to: '/customers/new' });
    }
    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
  }, [canEdit, navigate]);

  const columns = useMemo(() => {
    const date = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });

    return [
      {
        accessorKey: 'name',
        header: t('customer.name'),
        cell: ({ row, getValue }) => (
          <Link to="/customers/$customerId" params={{ customerId: row.original.id }} className="font-medium text-slate-900 hover:underline">
            {getValue()}
          </Link>
        ),
      },
      { accessorKey: 'email', header: t('customer.email') },
      { accessorKey: 'phone', header: t('customer.phone'), enableSorting: false, cell: ({ getValue }) => getValue() ?? '' },
      {
        id: 'location',
        header: t('customer.location'),
        enableSorting: false,
        accessorFn: (customer) => {
          const address = primaryAddress(customer);

          return address ? `${address.city}, ${countryName(address.countryCode, locale)}` : '';
        },
      },
      {
        accessorKey: 'createdAt',
        header: t('customer.createdAt'),
        cell: ({ getValue }) => <time dateTime={getValue()}>{date.format(new Date(getValue()))}</time>,
      },
    ];
  }, [t, locale]);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{t('customers.title')}</h1>
          <p className="text-sm text-slate-500">{t('customers.subtitle')}</p>
        </div>
        {canEdit ? (
          <Link
            to="/customers/new"
            className="inline-flex h-9 items-center gap-2 rounded-md bg-accent px-4 text-sm font-medium text-accent-fg hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            aria-keyshortcuts="N"
          >
            <Plus className="size-4" aria-hidden="true" />
            {t('customers.new')}
          </Link>
        ) : null}
      </div>

      {list.error ? <ErrorNotice error={list.error} /> : null}

      <DataTable
        {...urlView}
        fill
        manual
        label={t('customers.title')}
        data={list.data?.member ?? []}
        rowCount={list.data?.totalItems ?? 0}
        loading={list.isPending}
        columns={columns}
        getRowId={(customer) => customer.id}
        getRowLabel={(customer) => customer.name}
        onRowActivate={(customer) => navigate({ to: '/customers/$customerId', params: { customerId: customer.id } })}
        emptyMessage={t('customers.empty')}
      />
    </div>
  );
}
