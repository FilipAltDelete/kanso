import { useId, useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, PackageX, Pencil, Truck } from 'lucide-react';
import { useCorrectShipment, useOrder, useTransitionOrder, useVoidShipment } from '../../api/orders.js';
import { Badge, Button, Card, ErrorNotice, Input, Spinner } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatMoney } from '../../lib/money.js';
import { CancelItemsDialog } from './CancelItemsDialog.jsx';
import { EditOrderDialog } from './EditOrderDialog.jsx';
import { NoteForm, PaymentCard, TagsCard } from './OrderAnnotations.jsx';
import { useOrderDetailShortcuts } from './orderShortcuts.js';
import { PrintDocument, PrintDocuments } from './PrintDocuments.jsx';
import { ShipDialog } from './ShipDialog.jsx';
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
  const anyCancelled = order.lines.some((line) => line.cancelledQuantity > 0);
  useOrderDetailShortcuts();

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
          {t('orders.placedVia', { channel: order.channel.name })}
          {order.externalReference ? <> ({t('orders.externalReference', { reference: order.externalReference })})</> : null} · <time dateTime={order.placedAt}>{dateTime(order.placedAt)}</time>
          {order.location ? <> · {t('orders.shipsFrom', { location: `${order.location.code} · ${order.location.name}` })}</> : null}
        </p>
      </div>

      <Transitions order={order} />

      <div className="flex flex-wrap gap-2">
        <ShipAction order={order} />
        <ChangeActions order={order} />
      </div>

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
              <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.shipped')}</th>
              {anyCancelled ? <th scope="col" className="px-4 py-2 text-right font-medium">{t('order.cancelled')}</th> : null}
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
                <td className="px-4 py-2 text-right tabular-nums">{line.shippedQuantity}</td>
                {anyCancelled ? <td className="px-4 py-2 text-right tabular-nums">{line.cancelledQuantity}</td> : null}
                <td className="px-4 py-2 text-right tabular-nums">{money(line.unitPrice)}</td>
                <td className="px-4 py-2 text-right tabular-nums">{money(line.lineTotal)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <th scope="row" colSpan={anyCancelled ? 7 : 6} className="px-4 py-2 text-right font-semibold">
                {t('order.total')}
              </th>
              <td className="px-4 py-2 text-right font-semibold tabular-nums">{money(order.total)}</td>
            </tr>
          </tfoot>
        </table>
      </Card>

      <Shipments order={order} />

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
              data-shortcut={name === order.availableTransitions[0] && !CONFIRM_FIRST.has(name) ? 'advance' : undefined}
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
      case 'shipment':
        return t('orderEvent.shipment', {
          units: (event.after?.lines ?? []).reduce((sum, line) => sum + (line.quantity ?? 0), 0),
          tracking: [event.after?.carrier, event.after?.trackingNumber].filter(Boolean).join(' ') || t('shipment.noTracking'),
        });
      case 'shipment_voided':
        return t('orderEvent.shipmentVoided', { units: (event.after?.lines ?? []).reduce((sum, line) => sum + (line.quantity ?? 0), 0) });
      case 'shipment_corrected':
        return t('orderEvent.shipmentCorrected', {
          before: [event.before?.carrier, event.before?.trackingNumber].filter(Boolean).join(' ') || '—',
          after: [event.after?.carrier, event.after?.trackingNumber].filter(Boolean).join(' ') || '—',
        });
      case 'note':
        return t('orderEvent.note');
      case 'payment_status_changed':
        return t('orderEvent.payment', { from: payment(event.before), to: payment(event.after) });
      case 'edited':
        return t('orderEvent.edited', { changes: describeEdit(event, t) });
      case 'lines_cancelled':
        return [
          t('orderEvent.linesCancelled', {
            items: (event.after?.lines ?? []).map((line) => t('orderEvent.cancelledItem', { quantity: line.cancelled, sku: line.sku })).join(', '),
          }),
          event.after?.reason ? t('orderEvent.cancelReason', { reason: event.after.reason }) : null,
        ]
          .filter(Boolean)
          .join(' · ');
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

/** What an edit changed, in a few words: each line's quantity, lines added and removed, and which details. */
function describeEdit(event, t) {
  const before = new Map((event.before?.lines ?? []).map((line) => [line.position, line]));
  const after = new Map((event.after?.lines ?? []).map((line) => [line.position, line]));
  const parts = [];
  for (const position of [...new Set([...before.keys(), ...after.keys()])].sort((a, b) => a - b)) {
    const old = before.get(position);
    const now = after.get(position);
    if (old && now) parts.push(t('orderEvent.edited.quantity', { sku: now.sku, from: old.quantity, to: now.quantity }));
    else if (now) parts.push(t('orderEvent.edited.added', { sku: now.sku, quantity: now.quantity }));
    else parts.push(t('orderEvent.edited.removed', { sku: old.sku }));
  }
  if ('customerName' in (event.after ?? {}) || 'customerEmail' in (event.after ?? {})) parts.push(t('orderEvent.edited.customer'));
  if ('shippingAddress' in (event.after ?? {})) parts.push(t('orderEvent.edited.shippingAddress'));
  if ('billingAddress' in (event.after ?? {})) parts.push(t('orderEvent.edited.billingAddress'));

  return parts.join(', ');
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

/** The "Ship" button, for operators, while the order has units that can ship. */
function ShipAction({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const [open, setOpen] = useState(false);

  if (!canOperate || !order.canShip) return null;

  return (
    <>
      <Button size="sm" data-shortcut="ship" onClick={() => setOpen(true)}>
        <Truck className="size-4" aria-hidden="true" />
        {t('ship.open')}
      </Button>
      {open ? <ShipDialog order={order} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

/** "Edit order" and "Cancel items", for operators, while the order allows them. */
function ChangeActions({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const [open, setOpen] = useState(null);

  if (!canOperate || (!order.canEdit && !order.canCancelItems)) return null;

  return (
    <>
      {order.canEdit ? (
        <Button size="sm" variant="outline" data-shortcut="edit" onClick={() => setOpen('edit')}>
          <Pencil className="size-4" aria-hidden="true" />
          {t('orderEdit.open')}
        </Button>
      ) : null}
      {order.canCancelItems ? (
        <Button size="sm" variant="outline" onClick={() => setOpen('cancel')}>
          <PackageX className="size-4" aria-hidden="true" />
          {t('cancelItems.open')}
        </Button>
      ) : null}
      {open === 'edit' ? <EditOrderDialog order={order} onClose={() => setOpen(null)} /> : null}
      {open === 'cancel' ? <CancelItemsDialog order={order} onClose={() => setOpen(null)} /> : null}
    </>
  );
}

/** What has left, parcel by parcel; a voided one stays listed, struck through. */
function Shipments({ order }) {
  const { t } = useI18n();
  const shipments = order.shipments;

  if (shipments.length === 0) return null;

  return (
    <section aria-labelledby="order-shipments" className="space-y-2">
      <h2 id="order-shipments" className="text-sm font-semibold text-slate-900">
        {t('shipment.title')}
      </h2>
      <ul className="space-y-2">
        {[...shipments].reverse().map((shipment) => (
          <li key={shipment.id}>
            <ShipmentCard order={order} shipment={shipment} number={shipments.indexOf(shipment) + 1} />
          </li>
        ))}
      </ul>
    </section>
  );
}

function ShipmentCard({ order, shipment, number }) {
  const { t } = useI18n();
  const dateTime = useDateTime();
  const canOperate = useCanOperate();
  const [mode, setMode] = useState(null);
  const voided = Boolean(shipment.voidedAt);

  return (
    <Card className={voided ? 'p-3 text-sm text-slate-500' : 'p-3 text-sm'}>
      <p className="flex flex-wrap items-baseline gap-x-2">
        <span className="text-xs text-slate-500">{t('shipment.number', { number })}</span>
        <span className={voided ? 'font-medium line-through' : 'font-medium'}>{shipment.carrier ?? t('shipment.noCarrier')}</span>
        {shipment.trackingNumber ? (
          <span className={voided ? 'font-mono line-through' : 'font-mono'}>{shipment.trackingNumber}</span>
        ) : (
          <span className="text-slate-500">{t('shipment.noTracking')}</span>
        )}
        {voided ? <Badge tone="amber">{t('shipment.voided')}</Badge> : null}
      </p>
      <p className={voided ? 'line-through' : 'text-slate-700'}>
        {shipment.lines.map((line) => t('shipment.line', { quantity: line.quantity, sku: line.sku })).join(', ')}
      </p>
      <p className="text-xs text-slate-500">
        {t('shipment.from', { location: shipment.location.code })} · {shipment.actor.name} · <time dateTime={shipment.shippedAt}>{dateTime(shipment.shippedAt)}</time>
      </p>
      {voided ? (
        <p className="text-xs text-slate-500">
          {t('shipment.voidedBy', { name: shipment.voidedBy?.name ?? '' })} · <time dateTime={shipment.voidedAt}>{dateTime(shipment.voidedAt)}</time>
          {shipment.voidReason ? <> · {shipment.voidReason}</> : null}
        </p>
      ) : (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <PrintDocument order={order} type="packing_slip" shipmentId={shipment.id} label={t('shipment.packingSlip')} />
          {canOperate && mode === null ? (
            <>
              <Button size="sm" variant="ghost" onClick={() => setMode('correct')}>
                {t('shipment.correct')}
              </Button>
              {shipment.voidable ? (
                <Button size="sm" variant="ghost" onClick={() => setMode('void')}>
                  {t('shipment.void')}
                </Button>
              ) : null}
            </>
          ) : null}
        </div>
      )}
      {mode === 'correct' ? <CorrectShipment order={order} shipment={shipment} onDone={() => setMode(null)} /> : null}
      {mode === 'void' ? <VoidShipment order={order} shipment={shipment} onDone={() => setMode(null)} /> : null}
    </Card>
  );
}

/** Carrier and tracking number, typed again. Moves no stock. */
function CorrectShipment({ order, shipment, onDone }) {
  const { t } = useI18n();
  const correct = useCorrectShipment(order.id);
  const [carrier, setCarrier] = useState(shipment.carrier ?? '');
  const [tracking, setTracking] = useState(shipment.trackingNumber ?? '');
  const carrierId = useId();
  const trackingId = useId();

  function submit(event) {
    event.preventDefault();
    correct.mutate(
      { shipmentId: shipment.id, version: order.version, carrier: carrier.trim() || null, trackingNumber: tracking.trim() || null },
      { onSuccess: onDone },
    );
  }

  return (
    <form onSubmit={submit} className="mt-3 grid gap-2 border-t border-slate-200 pt-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <div>
        <label htmlFor={carrierId} className="mb-1 block text-xs font-medium">
          {t('ship.carrier')}
        </label>
        <Input id={carrierId} maxLength={64} value={carrier} onChange={(event) => setCarrier(event.target.value)} />
      </div>
      <div>
        <label htmlFor={trackingId} className="mb-1 block text-xs font-medium">
          {t('ship.trackingNumber')}
        </label>
        <Input id={trackingId} maxLength={128} className="font-mono" value={tracking} onChange={(event) => setTracking(event.target.value)} />
      </div>
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={correct.isPending}>
          {t('shipment.saveCorrection')}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
      {correct.error ? (
        <div className="sm:col-span-3">
          <ShipmentError error={correct.error} />
        </div>
      ) : null}
    </form>
  );
}

/** Taking back a shipment recorded by mistake; asks once, with an optional reason. */
function VoidShipment({ order, shipment, onDone }) {
  const { t } = useI18n();
  const voidShipment = useVoidShipment(order.id);
  const [reason, setReason] = useState('');
  const reasonId = useId();

  function submit(event) {
    event.preventDefault();
    voidShipment.mutate({ shipmentId: shipment.id, version: order.version, ...(reason.trim() ? { reason: reason.trim() } : {}) }, { onSuccess: onDone });
  }

  return (
    <form onSubmit={submit} role="group" aria-label={t('shipment.void')} className="mt-3 space-y-2 rounded-md border border-red-200 bg-red-50 p-3">
      <p className="text-sm text-red-800">{t(order.status === 'shipped' ? 'shipment.voidConfirmReopens' : 'shipment.voidConfirm')}</p>
      <div>
        <label htmlFor={reasonId} className="mb-1 block text-xs font-medium text-red-900">
          {t('shipment.voidReason')}
        </label>
        <Input id={reasonId} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} />
      </div>
      <div className="flex gap-2">
        <Button type="submit" size="sm" variant="danger" disabled={voidShipment.isPending}>
          {t('shipment.void')}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onDone}>
          {t('orders.keep')}
        </Button>
      </div>
      {voidShipment.error ? <ShipmentError error={voidShipment.error} /> : null}
    </form>
  );
}

function ShipmentError({ error }) {
  const { t } = useI18n();

  return error.status === 409 && error.violations?.[0]?.code === 'stale_version' ? (
    <p role="alert" className="text-sm text-amber-900">
      {t('orders.changedElsewhere')}
    </p>
  ) : (
    <ErrorNotice error={error} />
  );
}
