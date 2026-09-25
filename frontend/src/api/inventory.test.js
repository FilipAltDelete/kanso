import { EMPTY_VIEW } from '../components/ui/table/viewState.js';
import { listQuery, movementSchema, productSchema } from './inventory.js';

describe('the inventory API boundary', () => {
  it('turns a table view into the list query string', () => {
    const query = new URLSearchParams(
      listQuery({ ...EMPTY_VIEW, sorting: [{ id: 'name', desc: false }, { id: 'sku', desc: true }], globalFilter: 'tee', pageIndex: 2, pageSize: 50 }, { product: 'p1' }),
    );

    expect(Object.fromEntries(query)).toEqual({ page: '3', itemsPerPage: '50', sort: 'name,-sku', q: 'tee', product: 'p1' });
  });

  it('leaves out sort and search when there are none', () => {
    expect(listQuery(EMPTY_VIEW)).toBe('page=1&itemsPerPage=25');
  });

  it('accepts a product without barcode or weight', () => {
    const product = productSchema.parse({
      '@id': '/api/products/1',
      id: '1',
      sku: 'TEE-1',
      name: 'Tee',
      barcode: null,
      weightGrams: null,
      version: 1,
      onHand: 0,
      reserved: 0,
      available: 0,
      createdAt: '2026-09-25T10:00:00+00:00',
      updatedAt: '2026-09-25T10:00:00+00:00',
    });

    expect(product.barcode).toBeNull();
  });

  it('rejects a movement with an unknown reason', () => {
    expect(() =>
      movementSchema.parse({
        id: '1',
        type: 'adjustment',
        reason: 'theft',
        note: null,
        locationId: 'l',
        locationCode: 'WH1',
        locationName: 'Main',
        onHandChange: 1,
        onHandBefore: 0,
        onHandAfter: 1,
        reservedBefore: 0,
        reservedAfter: 0,
        actorName: 'Ops',
        occurredAt: '2026-09-25T10:00:00+00:00',
      }),
    ).toThrow();
  });
});
