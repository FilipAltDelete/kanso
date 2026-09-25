import { useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { useOrder, useTransitionOrder } from '../../api/orders.js';
import { Button, Card, ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatMoney } from '../../lib/money.js';
import { NoteForm, PaymentCard, TagsCard } from './OrderAnnotations.jsx';
import { PrintDocuments } from './PrintDocuments.jsx';
import { StatusBadge, useCanOperate, useDateTime } from './shared.jsx';

/** Transitions that end or pause the order ask once more before they run. */
const CONFIRM_FIRST = new Set(['cancel']);

export function OrderDetailPage() {
  const { orderId } = useParams({ from: '/orders/$orderId' });
  const { t } = useI18n();
  const order = useOrder(orderId);

  if (order.isPending) return <Spinner label={t('orders.loading')} />;
  if (order.error) {
    return (
      <div className="space-y-4">
        <BackLink />
        {order.error.status === 404 ? <p>{t('orders.notFound')}</p> : <ErrorNotice error={order.error} />}
      </div>
    );
  }

  return <OrderDetail order={order.data} />;
}

function BackLink() {
  const { t } = useI18n();

  return (
    <Link to="/orders" className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
      <ArrowLeft className="size-4" aria-hidden="true" />
      {t('orders.back')}
    </Link>
  );
}

function OrderDetail({ order }) {
  const { t, locale } = useI18n();
  const dateTime = useDateTime();
  const money = (minor) => formatMoney(minor, order.currency, locale);

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <BackLink />
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold">{t('orders.detailTitle', { number: order.number })}</h1>
          <StatusBadge status={order.status} />
          {order.heldFrom ? <span className="text-sm text-slate-600">{t('orders.heldFrom', { status: t(`orderStatus.${order.heldFrom}`) })}</span> : null}
        </div>
        <p className="text-sm text-slate-500">
          {t('orders.placedVia', { channel: order.channel.name })} · <time dateTime={order.placedAt}>{dateTime(order.placedAt)}</time>
          {order.location ? <> · {t('orders.shipsFrom', { location: `${order.location.code} · ${order.location.name}` })}</> : null}
        </p>
      </div>

      <Transitions order={order} />

      <PrintDocuments order={order} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="p-4">
          <h2 className="text-sm font-semibold text-slate-900">{t('order.customer')}</h2>
          <p className="mt-2 text-sm">{order.customer.name}</p>
          {order.customer.email ? <p className="text-sm text-slate-600">{order.customer.email}</p> : null}
        </Card>
        <Card className="p-4">
          <h2 className="text-sm font-semibold text-slate-900">{t('order.shippingAddress')}</h2>
          <Address address={order.shippingAddress} />
        </Card>
        <Card className="p-4">
          <h2 className="text-sm font-semibold text-slate-900">{t('order.billingAddress')}</h2>
          {order.billingAddress ? <Address address={order.billingAddress} /> : <p className="mt-2 text-sm text-slate-600">{t('orders.billingSameAsShipping')}</p>}
        </Card>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <PaymentCard order={order} />
        <TagsCard order={order} />
      </div>

      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="px-4 pt-4 text-left text-sm font-semibold text-slate-900">{t('order.lines')}</caption>
          <thead>
            <tr className="border-b border-slate-200 text-left text-slate-600">
              <th scope="col" className="px-4 py-2 font-medium">{t('order.sku')}</th>
              <th scope="col" className="px-4 py-2 font-medium">{t('order.productName')}</th>
              <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.quantity')}</th>
              <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.reserved')}</th>
              <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.unitPrice')}</th>
              <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.lineTotal')}</th>
            </tr>
          </thead>
          <tbody>
            {order.lines.map((line) => (
              <tr key={line.id} className="border-b border-slate-100">
                <td className="px-4 py-2 font-mono text-xs">{line.sku}</td>
                <td className="px-4 py-2">{line.name}</td>
                <td className="px-4 py-2 text-right tabular-nums">{line.quantity}</td>
                <td className="px-4 py-2 text-right tabular-nums">{line.reservedQuantity}</td>
                <td className="px-4 py-2 text-right tabular-nums">{money(line.unitPrice)}</td>
                <td className="px-4 py-2 text-right tabular-nums">{money(line.lineTotal)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <th scope="row" colSpan={5} className="px-4 py-2 text-right font-semibold">
                {t('order.total')}
              </th>
              <td className="px-4 py-2 text-right font-semibold tabular-nums">{money(order.total)}</td>
            </tr>
          </tfoot>
        </table>
      </Card>

      <section aria-labelledby="order-timeline" className="space-y-4">
        <h2 id="order-timeline" className="text-sm font-semibold text-slate-900">
          {t('orders.timeline')}
        </h2>
        <NoteForm order={order} />
        <Timeline events={order.events} />
      </section>
    </div>
  );
}

function Address({ address }) {
  const lines = [address.name, address.line1, address.line2, [address.postalCode, address.city].join(' '), address.region, address.countryCode, address.phone];

  return (
    <address className="mt-2 text-sm not-italic">
      {lines.filter(Boolean).map((line) => (
        <div key={line}>{line}</div>
      ))}
    </address>
  );
}

function Transitions({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const transition = useTransitionOrder(order.id);
  const [confirming, setConfirming] = useState(null);

  if (!canOperate || order.availableTransitions.length === 0) return null;

  const run = (name) => {
    setConfirming(null);
    transition.mutate({ transition: name, version: order.version });
  };

  return (
    <section aria-labelledby="order-actions" className="space-y-2">
      <h2 id="order-actions" className="sr-only">
        {t('orders.actions')}
      </h2>
      <div className="flex flex-wrap gap-2">
        {order.availableTransitions.map((name) =>
          confirming === name ? (
            <div key={name} role="group" aria-label={t(`orderTransition.${name}`)} className="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-2 py-1">
              <span className="text-sm text-red-800">{t(`orderTransition.${name}.confirm`)}</span>
              <Button size="sm" variant="danger" onClick={() => run(name)} disabled={transition.isPending}>
                {t(`orderTransition.${name}`)}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirming(null)}>
                {t('orders.keep')}
              </Button>
            </div>
          ) : (
            <Button
              key={name}
              size="sm"
              variant={CONFIRM_FIRST.has(name) ? 'outline' : name === order.availableTransitions[0] ? 'default' : 'outline'}
              disabled={transition.isPending}
              onClick={() => (CONFIRM_FIRST.has(name) ? setConfirming(name) : run(name))}
            >
              {t(`orderTransition.${name}`)}
            </Button>
          ),
        )}
      </div>
      {transition.error?.status === 409 && transition.error.violations?.[0]?.code === 'stale_version' ? (
        <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          {t('orders.changedElsewhere')}
        </p>
      ) : transition.error?.violations?.some((violation) => violation.code === 'insufficient_stock') ? (
        <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          {t('orders.insufficientStock', { location: order.location?.code ?? '', skus: shortSkus(order, transition.error.violations).join(', ') })}
        </p>
      ) : transition.error ? (
        <ErrorNotice error={transition.error} />
      ) : null}
    </section>
  );
}

/** The SKUs of the lines a stock conflict names (`lines[1].quantity` is the second line). */
function shortSkus(order, violations) {
  return violations
    .filter((violation) => violation.code === 'insufficient_stock')
    .map((violation) => order.lines[Number(/^lines\[(\d+)\]/.exec(violation.path)?.[1])]?.sku)
    .filter(Boolean);
}

/** What one event says in the history, in the viewer's language. */
function useDescribeEvent() {
  const { t } = useI18n();
  const status = (state) => (state?.status ? t(`orderStatus.${state.status}`) : '');
  const payment = (state) => (state?.paymentStatus ? t(`paymentStatus.${state.paymentStatus}`) : '');

  return (event) => {
    switch (event.type) {
      case 'created':
        return t('orderEvent.created');
      case 'transition':
        return t('orderEvent.transition', { transition: t(`orderTransition.${event.transition}`), from: status(event.before), to: status(event.after) });
      case 'note':
        return t('orderEvent.note');
      case 'payment_status_changed':
        return t('orderEvent.payment', { from: payment(event.before), to: payment(event.after) });
      case 'tags_changed': {
        const before = event.before?.tags ?? [];
        const after = event.after?.tags ?? [];
        const added = after.filter((tag) => !before.includes(tag));
        const removed = before.filter((tag) => !after.includes(tag));

        return [added.length ? t('orderEvent.tagsAdded', { tags: added.join(', ') }) : null, removed.length ? t('orderEvent.tagsRemoved', { tags: removed.join(', ') }) : null]
          .filter(Boolean)
          .join(' · ');
      }
      default:
        return event.type;
    }
  };
}

function Timeline({ events }) {
  const dateTime = useDateTime();
  const describe = useDescribeEvent();

  return (
    <ol className="space-y-3 border-l border-slate-200 pl-4">
      {[...events].reverse().map((event) => (
        <li key={event.id} className="relative">
          <span className="absolute -left-[1.3rem] top-1.5 size-2 rounded-full bg-slate-400" aria-hidden="true" />
          <p className="text-sm text-slate-900">{describe(event)}</p>
          {event.type === 'note' ? <blockquote className="mt-1 whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-800">{event.after?.note}</blockquote> : null}
          <p className="text-xs text-slate-500">
            {event.actor.name} · <time dateTime={event.occurredAt}>{dateTime(event.occurredAt)}</time>
          </p>
        </li>
      ))}
    </ol>
  );
}
