import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

/** Why stock was adjusted; the API's list, in the order the dialog offers them. */
export const ADJUSTMENT_REASONS = ['received', 'count', 'damaged', 'lost', 'found', 'returned', 'correction', 'other'];

const quantity = z.number().int();

export const productSchema = z.object({
  id: z.string(),
  sku: z.string(),
  name: z.string(),
  barcode: z.string().nullable(),
  weightGrams: z.number().int().nullable(),
  version: z.number().int(),
  onHand: quantity,
  reserved: quantity,
  available: quantity,
  createdAt: z.string(),
  updatedAt: z.string(),
});

export const locationSchema = z.object({
  id: z.string(),
  code: z.string(),
  name: z.string(),
  addressLine1: z.string().nullable(),
  addressLine2: z.string().nullable(),
  postalCode: z.string().nullable(),
  city: z.string().nullable(),
  countryCode: z.string().nullable(),
  version: z.number().int(),
});

export const inventoryLevelSchema = z.object({
  id: z.string(),
  productId: z.string(),
  locationId: z.string(),
  locationCode: z.string(),
  locationName: z.string(),
  onHand: quantity,
  reserved: quantity,
  available: quantity,
  version: z.number().int(),
  updatedAt: z.string(),
});

export const movementSchema = z.object({
  id: z.string(),
  type: z.string(),
  reason: z.enum(ADJUSTMENT_REASONS).nullable(),
  note: z.string().nullable(),
  locationId: z.string(),
  locationCode: z.string(),
  locationName: z.string(),
  onHandChange: quantity,
  onHandBefore: quantity,
  onHandAfter: quantity,
  reservedBefore: quantity,
  reservedAfter: quantity,
  actorName: z.string(),
  occurredAt: z.string(),
});

const pageOf = (item) => z.object({ member: z.array(item), totalItems: z.number().int() });

/** A table view (components/ui/table) as a list endpoint's query string: q, sort, page, itemsPerPage. */
export function listQuery(view, extra = {}) {
  const params = new URLSearchParams();
  params.set('page', String(view.pageIndex + 1));
  params.set('itemsPerPage', String(view.pageSize));
  if (view.sorting.length > 0) params.set('sort', view.sorting.map(({ id, desc }) => `${desc ? '-' : ''}${id}`).join(','));
  if (view.globalFilter) params.set('q', view.globalFilter);
  for (const [key, value] of Object.entries(extra)) params.set(key, value);

  return params.toString();
}

function usePage(key, path, schema, view, extra) {
  const query = listQuery(view, extra);

  return useQuery({
    queryKey: [key, query],
    queryFn: async ({ signal }) => pageOf(schema).parse(await api(`${path}?${query}`, { signal })),
    // Keep the rows on screen while the next page or sort loads.
    placeholderData: keepPreviousData,
  });
}

export function useProducts(view) {
  return usePage('products', '/api/products', productSchema, view);
}

export function useProduct(id) {
  return useQuery({
    queryKey: ['product', id],
    queryFn: async ({ signal }) => productSchema.parse(await api(`/api/products/${encodeURIComponent(id)}`, { signal })),
  });
}

export function useLocations(view) {
  return usePage('locations', '/api/locations', locationSchema, view);
}

/**
 * Every location, for showing a product's stock at each one. An installation
 * has a handful to a few hundred; the API's largest page covers that.
 */
export function useAllLocations() {
  return useQuery({
    queryKey: ['locations', 'all'],
    queryFn: async ({ signal }) => pageOf(locationSchema).parse(await api('/api/locations?itemsPerPage=500&sort=code', { signal })).member,
    staleTime: 60 * 1000,
  });
}

export function useInventoryLevels(productId) {
  return useQuery({
    queryKey: ['inventoryLevels', productId],
    queryFn: async ({ signal }) =>
      pageOf(inventoryLevelSchema).parse(await api(`/api/inventory-levels?product=${encodeURIComponent(productId)}&itemsPerPage=500`, { signal })).member,
  });
}

export function useMovements(productId, view) {
  return usePage('movements', '/api/inventory-movements', movementSchema, view, { product: productId });
}

/**
 * One manual adjustment. Whatever the outcome, the product's numbers are
 * refetched: after a success they changed, and after a 409 the ones on
 * screen are the reason it failed.
 */
export function useAdjustStock(productId) {
  const queryClient = useQueryClient();
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['inventoryLevels', productId] }),
      queryClient.invalidateQueries({ queryKey: ['product', productId] }),
      queryClient.invalidateQueries({ queryKey: ['movements'] }),
      queryClient.invalidateQueries({ queryKey: ['products'] }),
    ]);

  return useMutation({
    mutationFn: async (body) => movementSchema.parse(await api('/api/stock-adjustments', { method: 'POST', body: { ...body, productId } })),
    onSettled: refresh,
  });
}
