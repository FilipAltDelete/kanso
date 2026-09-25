import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';
import { importQuery } from './importRuns.js';

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
  // The order behind a reservation, release or shipment.
  orderId: z.string().nullish(),
  orderNumber: z.string().nullish(),
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

/** The catalog changed: every product and location list refetches, and a changed product's history. */
function useCatalogRefresh(key) {
  const queryClient = useQueryClient();

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: [key] }),
      ...(key === 'products' ? [queryClient.invalidateQueries({ queryKey: ['productEvents'] })] : []),
    ]);
}

export function useCreateProduct() {
  const refresh = useCatalogRefresh('products');

  return useMutation({
    mutationFn: async (body) => productSchema.parse(await api('/api/products', { method: 'POST', body })),
    onSuccess: refresh,
  });
}

/** An edit carries the version it was based on; a 409 means someone saved in between, and the product is refetched. */
export function useUpdateProduct(id) {
  const queryClient = useQueryClient();
  const refresh = useCatalogRefresh('products');

  return useMutation({
    mutationFn: async (body) =>
      productSchema.parse(await api(`/api/products/${encodeURIComponent(id)}`, { method: 'PATCH', body, headers: { 'Content-Type': 'application/merge-patch+json' } })),
    onSuccess: (product) => {
      queryClient.setQueryData(['product', id], product);
      refresh();
    },
    onError: (error) => {
      if (error.status === 409) queryClient.invalidateQueries({ queryKey: ['product', id] });
    },
  });
}

export function useCreateLocation() {
  const refresh = useCatalogRefresh('locations');

  return useMutation({
    mutationFn: async (body) => locationSchema.parse(await api('/api/locations', { method: 'POST', body })),
    onSuccess: refresh,
  });
}

export function useUpdateLocation(id) {
  const refresh = useCatalogRefresh('locations');

  return useMutation({
    mutationFn: async (body) =>
      locationSchema.parse(await api(`/api/locations/${encodeURIComponent(id)}`, { method: 'PATCH', body, headers: { 'Content-Type': 'application/merge-patch+json' } })),
    // After a 409 too: the list then shows the version the next attempt must send.
    onSettled: refresh,
  });
}

export const importResultSchema = z.object({
  dryRun: z.boolean(),
  rows: z.number().int(),
  created: z.number().int(),
  updated: z.number().int(),
  unchanged: z.number().int(),
  failed: z.number().int(),
  errors: z.array(z.object({ row: z.number().int(), sku: z.string().nullable(), field: z.string(), code: z.string(), message: z.string() })),
  importRunId: z.string().nullish(),
});

/** `{ file, dryRun }`: a dry run is the preview, and writes nothing. */
export function useImportProducts() {
  const queryClient = useQueryClient();
  const refresh = useCatalogRefresh('products');

  return useMutation({
    mutationFn: async ({ file, dryRun }) =>
      importResultSchema.parse(await api(`/api/product-imports?${importQuery(file, dryRun)}`, { method: 'POST', body: file, headers: { 'Content-Type': 'text/csv' } })),
    onSuccess: (result) => {
      if (result.dryRun) return;
      refresh();
      queryClient.invalidateQueries({ queryKey: ['importRuns'] });
    },
  });
}

export const stockImportResultSchema = z.object({
  dryRun: z.boolean(),
  rows: z.number().int(),
  changed: z.number().int(),
  unchanged: z.number().int(),
  failed: z.number().int(),
  errors: z.array(
    z.object({ row: z.number().int(), sku: z.string().nullable(), location: z.string().nullable(), field: z.string(), code: z.string(), message: z.string() }),
  ),
  importRunId: z.string().nullish(),
});

/** `{ file, dryRun }`: counted quantities per SKU and location (ADR-0016); a dry run is the preview. */
export function useImportStock() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ file, dryRun }) =>
      stockImportResultSchema.parse(await api(`/api/stock-imports?${importQuery(file, dryRun)}`, { method: 'POST', body: file, headers: { 'Content-Type': 'text/csv' } })),
    onSuccess: (result) => {
      if (result.dryRun) return;
      for (const queryKey of [['inventoryLevels'], ['movements'], ['products'], ['product'], ['importRuns']]) {
        queryClient.invalidateQueries({ queryKey });
      }
    },
  });
}

const fieldValue = z.union([z.string(), z.number(), z.null()]);

export const productEventSchema = z.object({
  id: z.string(),
  productId: z.string(),
  type: z.enum(['created', 'updated']),
  source: z.string(),
  actorId: z.string(),
  actorName: z.string(),
  before: z.record(z.string(), fieldValue).nullable(),
  after: z.record(z.string(), fieldValue),
  occurredAt: z.string(),
});

/** A product's history of creates and changes (ADR-0013), newest first. */
export function useProductEvents(productId, view) {
  return usePage('productEvents', '/api/product-events', productEventSchema, view, { product: productId });
}
