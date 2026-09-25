import { screen, within } from '@testing-library/react';
import { api } from '../../api/client.js';
import { renderAt } from '../orders/testing.jsx';
import { changedFields } from './ProductChanges.jsx';

vi.mock('../../api/client.js', () => ({ api: vi.fn(), ApiError: class extends Error {} }));

const product = {
  id: 'p1',
  sku: 'TEE-1',
  name: 'Better tee',
  barcode: null,
  weightGrams: 180,
  version: 2,
  onHand: 0,
  reserved: 0,
  available: 0,
  createdAt: '2026-09-25T10:00:00+00:00',
  updatedAt: '2026-09-26T10:00:00+00:00',
};
const events = [
  {
    id: 'e2',
    productId: 'p1',
    type: 'updated',
    source: 'import',
    actorId: 'u1',
    actorName: 'Olle',
    before: { sku: 'TEE-1', name: 'Tee', barcode: '123', weightGrams: 180 },
    after: { sku: 'TEE-1', name: 'Better tee', barcode: null, weightGrams: 180 },
    occurredAt: '2026-09-26T10:00:00+00:00',
  },
  {
    id: 'e1',
    productId: 'p1',
    type: 'created',
    source: 'api',
    actorId: 'system',
    actorName: 'System',
    before: null,
    after: { sku: 'TEE-1', name: 'Tee', barcode: '123', weightGrams: 180 },
    occurredAt: '2026-09-25T10:00:00+00:00',
  },
];

describe('changedFields', () => {
  it('lists only the fields that differ, in field order', () => {
    expect(changedFields(events[0])).toEqual([
      { field: 'name', from: 'Tee', to: 'Better tee' },
      { field: 'barcode', from: '123', to: null },
    ]);
  });

  it('has nothing to list for a create', () => {
    expect(changedFields(events[1])).toEqual([]);
  });
});

describe('the product changes section', () => {
  beforeEach(() => {
    api.mockReset();
    api.mockImplementation((path) => {
      if (path.startsWith('/api/products/p1')) return Promise.resolve(product);
      if (path.startsWith('/api/product-events')) return Promise.resolve({ member: events, totalItems: 2 });

      return Promise.resolve({ member: [], totalItems: 0 });
    });
  });

  it('shows who changed what, through what, newest first', async () => {
    renderAt('/products/p1');

    const section = await screen.findByRole('region', { name: 'Product changes' });
    expect(await within(section).findByText('Olle')).toBeTruthy();
    expect(within(section).getByText('Tee → Better tee')).toBeTruthy();
    expect(within(section).getByText('123 → —')).toBeTruthy();
    expect(within(section).getByText('CSV import')).toBeTruthy();
    expect(within(section).getByText('Created')).toBeTruthy();
    expect(api).toHaveBeenCalledWith(expect.stringMatching(/^\/api\/product-events\?.*product=p1/), expect.anything());
  });
});
