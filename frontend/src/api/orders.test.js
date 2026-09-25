import { orderListQuery, orderSchema } from './orders.js';
import { orderFixture } from '../features/orders/testing.jsx';

const view = { sorting: [{ id: 'placedAt', desc: true }], globalFilter: '', columnFilters: [], pageIndex: 0, pageSize: 25 };

describe('the order list query', () => {
  it('turns a table view into the API’s parameters', () => {
    const query = new URLSearchParams(
      orderListQuery({
        ...view,
        sorting: [{ id: 'total', desc: false }, { id: 'placedAt', desc: true }],
        globalFilter: 'anna',
        columnFilters: [
          { id: 'status', value: 'on_hold' },
          { id: 'channel', value: 'manual' },
        ],
        pageIndex: 2,
        pageSize: 50,
      }),
    );

    expect(Object.fromEntries(query)).toEqual({ page: '3', itemsPerPage: '50', sort: 'total,-placedAt', q: 'anna', status: 'on_hold', channel: 'manual' });
  });

  it('filters by tag and payment status', () => {
    const query = new URLSearchParams(
      orderListQuery({
        ...view,
        columnFilters: [
          { id: 'tags', value: 'VIP' },
          { id: 'paymentStatus', value: 'paid' },
        ],
      }),
    );

    expect(query.get('tag')).toBe('VIP');
    expect(query.get('paymentStatus')).toBe('paid');
  });

  it('sends a range of local days as UTC instants, the last day included', () => {
    const query = new URLSearchParams(orderListQuery({ ...view, columnFilters: [{ id: 'placedAt', value: '2026-09-01..2026-09-30' }] }));

    expect(query.get('placedFrom')).toBe(new Date(2026, 8, 1).toISOString());
    expect(query.get('placedBefore')).toBe(new Date(2026, 9, 1).toISOString());
  });

  it('sends a ship-date range the same way', () => {
    const query = new URLSearchParams(orderListQuery({ ...view, columnFilters: [{ id: 'shippedAt', value: '2026-09-26..2026-09-26' }] }));

    expect(query.get('shippedFrom')).toBe(new Date(2026, 8, 26).toISOString());
    expect(query.get('shippedBefore')).toBe(new Date(2026, 8, 27).toISOString());
    expect(query.has('placedFrom')).toBe(false);
  });

  it('allows an open-ended range', () => {
    const query = new URLSearchParams(orderListQuery({ ...view, columnFilters: [{ id: 'placedAt', value: '..2026-09-30' }] }));

    expect(query.has('placedFrom')).toBe(false);
    expect(query.get('placedBefore')).toBe(new Date(2026, 9, 1).toISOString());
  });
});

describe('the order schema', () => {
  it('accepts an order whose null fields the API left out', () => {
    const order = orderSchema.parse(orderFixture());

    expect(order.heldFrom).toBeUndefined();
    expect(order.billingAddress).toBeUndefined();
    expect(order.total).toBe(69650);
  });

  it('defaults the payment status and tags of an order from before they existed', () => {
    const { paymentStatus, tags, ...older } = orderFixture();

    expect(orderSchema.parse(older)).toMatchObject({ paymentStatus: 'unpaid', tags: [] });
    expect(paymentStatus).toBe('unpaid');
    expect(tags).toEqual([]);
  });

  it('refuses a fractional amount', () => {
    expect(() => orderSchema.parse(orderFixture({ total: 696.5 }))).toThrow();
  });
});
