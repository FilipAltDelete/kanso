import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Pencil, Plus } from 'lucide-react';
import { customers } from '../../api/customers.js';
import { useCustomerOrders } from '../../api/orders.js';
import { formatMoney } from '../../lib/money.js';
import { StatusBadge, useCanOperate, useDateTime } from '../orders/shared.jsx';
import { Badge, Card, EmptyState, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { countryName } from '../../lib/countries.js';
import { useI18n } from '../../lib/i18n.jsx';
import { useCanEditCustomers } from './permissions.js';

const linkButton =
  'inline-flex h-9 items-center gap-2 rounded-md px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900';

export function Address({ address, locale }) {
  return (
    <address className="text-sm not-italic leading-6 text-slate-700">
      {[address.name, address.company, address.line1, address.line2].filter(Boolean).map((line) => (
        <span key={line} className="block">
          {line}
        </span>
      ))}
      <span className="block">{[address.postalCode, address.city].join(' ')}</span>
      {address.region ? <span className="block">{address.region}</span> : null}
      <span className="block">{countryName(address.countryCode, locale)}</span>
      {address.phone ? <span className="block">{address.phone}</span> : null}
    </address>
  );
}

function AddressList({ title, addresses, locale, t }) {
  return (
    <section aria-labelledby={`addresses-${title}`}>
      <h3 id={`addresses-${title}`} className="text-sm font-medium text-slate-500">
        {t(`customer.addresses.${title}`)}
      </h3>
      {addresses.length === 0 ? (
        <p className="mt-2 text-sm text-slate-500">{t('customer.addresses.none')}</p>
      ) : (
        <ul className="mt-2 space-y-3">
          {addresses.map((address) => (
            <li key={address.id} className="rounded-md border border-slate-200 p-3">
              {address.isDefault ? (
                <Badge tone="green" className="mb-1">
                  {t('customer.address.default')}
                </Badge>
              ) : null}
              <Address address={address} locale={locale} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** A changed value as the history shows it; addresses are too large to show inline. */
function describeChange(field, change, t) {
  if (field === 'addresses') return t('customer.history.addressesChanged');
  const show = (value) => (value === null || value === undefined || value === '' ? t('customer.history.empty') : String(value));

  return t('customer.history.fieldChanged', { field: t(`customer.${field}`), before: show(change.before), after: show(change.after) });
}

function History({ customerId }) {
  const { t, locale } = useI18n();
  const history = useQuery({
    queryKey: ['customers', 'history', customerId],
    queryFn: ({ signal }) => customers.history(customerId, {}, signal),
  });
  const dateTime = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });

  if (history.isPending) return <Spinner label={t('common.loading')} />;
  if (history.error) return <ErrorNotice error={history.error} />;

  return (
    <ol className="space-y-3">
      {history.data.member.map((event) => (
        <li key={event.id} className="border-l-2 border-slate-200 pl-3 text-sm">
          <p>
            <span className="font-medium">{t(`customer.history.${event.type}`)}</span>{' '}
            <span className="text-slate-500">{t('customer.history.by', { actor: event.actor })}</span>
          </p>
          <time dateTime={event.occurredAt} className="text-xs text-slate-500">
            {dateTime.format(new Date(event.occurredAt))}
          </time>
          {event.type === 'updated' ? (
            <ul className="mt-1 list-inside list-disc text-slate-700">
              {Object.entries(event.changes).map(([field, change]) => (
                <li key={field}>{describeChange(field, change, t)}</li>
              ))}
            </ul>
          ) : null}
        </li>
      ))}
    </ol>
  );
}

export function CustomerDetailPage() {
  const { customerId } = useParams({ strict: false });
  const { t, locale } = useI18n();
  const canEdit = useCanEditCustomers();
  const canOperate = useCanOperate();
  const customer = useQuery({
    queryKey: ['customers', 'detail', customerId],
    queryFn: ({ signal }) => customers.get(customerId, signal),
  });

  const back = (
    <Link to="/customers" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:underline">
      <ArrowLeft className="size-4" aria-hidden="true" />
      {t('customers.back')}
    </Link>
  );

  if (customer.isPending) return <Spinner label={t('common.loading')} />;
  if (customer.error) {
    return (
      <div className="space-y-4">
        {back}
        <ErrorNotice error={customer.error.status === 404 ? { message: t('customers.notFound') } : customer.error} />
      </div>
    );
  }

  const data = customer.data;
  const date = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });
  const byType = (type) => data.addresses.filter((address) => address.type === type);

  return (
    <div className="space-y-6">
      {back}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{data.name}</h1>
          <p className="text-sm text-slate-500">{data.email}</p>
        </div>
        {canEdit ? (
          <Link
            to="/customers/$customerId/edit"
            params={{ customerId: data.id }}
            className={`${linkButton} border border-slate-300 bg-white text-slate-900 hover:bg-slate-50`}
          >
            <Pencil className="size-4" aria-hidden="true" />
            {t('customers.edit')}
          </Link>
        ) : null}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="p-4 lg:col-span-2">
          <h2 className="font-medium">{t('customer.details')}</h2>
          <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt className="text-slate-500">{t('customer.email')}</dt>
            <dd>
              <a href={`mailto:${data.email}`} className="hover:underline">
                {data.email}
              </a>
            </dd>
            <dt className="text-slate-500">{t('customer.phone')}</dt>
            <dd>{data.phone ? <a href={`tel:${data.phone.replace(/[^+\d]/g, '')}`}>{data.phone}</a> : '—'}</dd>
            <dt className="text-slate-500">{t('customer.createdAt')}</dt>
            <dd>
              <time dateTime={data.createdAt}>{date.format(new Date(data.createdAt))}</time>
            </dd>
            <dt className="text-slate-500">{t('customer.updatedAt')}</dt>
            <dd>
              <time dateTime={data.updatedAt}>{date.format(new Date(data.updatedAt))}</time>
            </dd>
          </dl>

          <h2 className="mt-6 font-medium">{t('customer.addresses')}</h2>
          <div className="mt-3 grid gap-4 sm:grid-cols-2">
            <AddressList title="shipping" addresses={byType('shipping')} locale={locale} t={t} />
            <AddressList title="billing" addresses={byType('billing')} locale={locale} t={t} />
          </div>
        </Card>

        <Card className="p-4">
          <h2 className="font-medium">{t('customer.history')}</h2>
          <div className="mt-3">
            <History customerId={data.id} />
          </div>
        </Card>
      </div>

      <section aria-labelledby="customer-orders" className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="customer-orders" className="font-medium">
            {t('customer.orders')}
          </h2>
          {canOperate ? (
            <Link to="/orders/new" search={{ customer: data.id }} className={`${linkButton} border border-slate-300 bg-white text-slate-900 hover:bg-slate-50`}>
              <Plus className="size-4" aria-hidden="true" />
              {t('customer.newOrder')}
            </Link>
          ) : null}
        </div>
        <CustomerOrders customerId={data.id} />
      </section>
    </div>
  );
}

/** The orders placed for this customer record, newest first. */
function CustomerOrders({ customerId }) {
  const { t, locale } = useI18n();
  const dateTime = useDateTime();
  const orders = useCustomerOrders(customerId);

  if (orders.isPending) return <Spinner label={t('common.loading')} />;
  if (orders.error) return <ErrorNotice error={orders.error} />;
  if (orders.data.member.length === 0) return <EmptyState title={t('customer.ordersEmpty')} />;

  return (
    <Card className="overflow-x-auto">
      <table className="w-full text-sm">
        <caption className="sr-only">{t('customer.orders')}</caption>
        <thead>
          <tr className="border-b border-slate-200 text-left text-slate-600">
            <th scope="col" className="px-4 py-2 font-medium">{t('order.number')}</th>
            <th scope="col" className="px-4 py-2 font-medium">{t('order.placedAt')}</th>
            <th scope="col" className="px-4 py-2 font-medium">{t('order.status')}</th>
            <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.total')}</th>
          </tr>
        </thead>
        <tbody>
          {orders.data.member.map((order) => (
            <tr key={order.id} className="border-b border-slate-100">
              <td className="px-4 py-2">
                <Link to="/orders/$orderId" params={{ orderId: order.id }} className="font-medium underline-offset-2 hover:underline">
                  {order.number}
                </Link>
              </td>
              <td className="px-4 py-2">
                <time dateTime={order.placedAt}>{dateTime(order.placedAt)}</time>
              </td>
              <td className="px-4 py-2">
                <StatusBadge status={order.status} />
              </td>
              <td className="px-4 py-2 text-right tabular-nums">{formatMoney(order.total, order.currency, locale)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {orders.data.totalItems > orders.data.member.length ? (
        <p className="px-4 py-2 text-sm text-slate-500">{t('customer.ordersMore', { shown: orders.data.member.length, total: orders.data.totalItems })}</p>
      ) : null}
    </Card>
  );
}
