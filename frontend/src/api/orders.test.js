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

  it('sends a range of local days as UTC instants, the last day included', () => {
    const query = new URLSearchParams(orderListQuery({ ...view, columnFilters: [{ id: 'placedAt', value: '2026-09-01..2026-09-30' }] }));

    expect(query.get('placedFrom')).toBe(new Date(2026, 8, 1).toISOString());
    expect(query.get('placedBefore')).toBe(new Date(2026, 9, 1).toISOString());
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

  it('refuses a fractional amount', () => {
    expect(() => orderSchema.parse(orderFixture({ total: 696.5 }))).toThrow();
  });
});
