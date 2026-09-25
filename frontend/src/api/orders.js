import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

export const ORDER_STATUSES = ['pending', 'confirmed', 'allocated', 'picking', 'packed', 'shipped', 'delivered', 'cancelled', 'on_hold'];
/** Confirmed but not yet shipped: the warehouse's queue (the dashboard counts the same). */
export const AWAITING_FULFILLMENT = ['confirmed', 'allocated', 'picking', 'packed'];
export const TRANSITIONS = ['confirm', 'allocate', 'start_picking', 'pack', 'ship', 'deliver', 'cancel', 'hold', 'release'];

// The API leaves null fields out of a response, so an optional field may be null or missing.
const status = z.enum(ORDER_STATUSES);
const minor = z.number().int();

const addressSchema = z.object({
  name: z.string().nullish(),
  line1: z.string(),
  line2: z.string().nullish(),
  postalCode: z.string(),
  city: z.string(),
  region: z.string().nullish(),
  countryCode: z.string(),
  phone: z.string().nullish(),
});

export const orderSummarySchema = z.object({
  id: z.string(),
  number: z.string(),
  status,
  heldFrom: status.nullish(),
  channel: z.object({ code: z.string(), name: z.string() }),
  currency: z.string().length(3),
  total: minor,
  customer: z.object({ id: z.string().nullish(), name: z.string(), email: z.string().nullish() }),
  lineCount: z.number().int(),
  placedAt: z.string(),
  updatedAt: z.string(),
  version: z.number().int(),
});

export const orderSchema = orderSummarySchema.extend({
  createdAt: z.string(),
  shippingAddress: addressSchema,
  // Where stock is reserved and shipped from; absent only on orders placed before orders had one.
  location: z.object({ id: z.string(), code: z.string(), name: z.string() }).nullish(),
  billingAddress: addressSchema.nullish(),
  lines: z.array(
    z.object({
      id: z.string(),
      position: z.number().int(),
      productId: z.string().nullish(),
      sku: z.string(),
      name: z.string(),
      quantity: z.number().int(),
      // Held in stock at the order's location: all of the line from confirm until ship or cancel.
      reservedQuantity: z.number().int().default(0),
      unitPrice: minor,
      lineTotal: minor,
    }),
  ),
  events: z.array(
    z.object({
      id: z.string(),
      type: z.string(),
      transition: z.string().nullish(),
      actor: z.object({ id: z.string(), name: z.string() }),
      before: z.record(z.unknown()).nullish(),
      after: z.record(z.unknown()).nullish(),
      occurredAt: z.string(),
    }),
  ),
  availableTransitions: z.array(z.enum(TRANSITIONS)),
});

export const orderPageSchema = z.object({
  member: z.array(orderSummarySchema),
  totalItems: z.number().int(),
});

export const channelsSchema = z.object({
  member: z.array(z.object({ code: z.string(), name: z.string(), type: z.string(), currency: z.string() })),
});

/** A local calendar day ("2026-09-26") as the UTC instant its midnight is, here. */
function localMidnight(day, addDays = 0) {
  const [year, month, date] = day.split('-').map(Number);

  return new Date(year, month - 1, date + addDays).toISOString();
}

/**
 * A table view (components/ui/table) as the list endpoint's query string.
 * The date filter is "from..to" in local days, both inclusive; the API takes
 * UTC instants, from inclusive and before exclusive.
 */
export function orderListQuery(view) {
  const params = new URLSearchParams();
  params.set('page', String(view.pageIndex + 1));
  params.set('itemsPerPage', String(view.pageSize));
  if (view.sorting.length > 0) params.set('sort', view.sorting.map(({ id, desc }) => `${desc ? '-' : ''}${id}`).join(','));
  if (view.globalFilter) params.set('q', view.globalFilter);

  for (const { id, value } of view.columnFilters) {
    if (id === 'placedAt') {
      const [from, to] = String(value).split('..');
      if (from) params.set('placedFrom', localMidnight(from));
      if (to) params.set('placedBefore', localMidnight(to, 1));
    } else if (id === 'status' || id === 'channel') {
      params.set(id, String(value));
    }
  }

  return params.toString();
}

export function useOrders(view) {
  const query = orderListQuery(view);

  return useQuery({
    queryKey: ['orders', query],
    queryFn: async ({ signal }) => orderPageSchema.parse(await api(`/api/orders?${query}`, { signal })),
    // Keep the rows on screen while the next page or sort loads.
    placeholderData: keepPreviousData,
  });
}

/** The orders placed for one customer record, newest first. */
export function useCustomerOrders(customerId) {
  return useQuery({
    queryKey: ['orders', 'customer', customerId],
    queryFn: async ({ signal }) =>
      orderPageSchema.parse(await api(`/api/orders?customer=${encodeURIComponent(customerId)}&sort=-placedAt&itemsPerPage=50`, { signal })),
  });
}

export function useOrder(id) {
  return useQuery({
    queryKey: ['order', id],
    queryFn: async ({ signal }) => orderSchema.parse(await api(`/api/orders/${encodeURIComponent(id)}`, { signal })),
  });
}

export function useChannels() {
  return useQuery({
    queryKey: ['channels'],
    queryFn: async ({ signal }) => channelsSchema.parse(await api('/api/channels', { signal })).member,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateOrder() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body) => orderSchema.parse(await api('/api/orders', { method: 'POST', body })),
    onSuccess: (order) => {
      queryClient.setQueryData(['order', order.id], order);
      queryClient.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}

export function useTransitionOrder(id) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ transition, version }) =>
      orderSchema.parse(await api(`/api/orders/${encodeURIComponent(id)}/transitions`, { method: 'POST', body: { transition, version } })),
    onSuccess: (order) => {
      queryClient.setQueryData(['order', id], order);
      queryClient.invalidateQueries({ queryKey: ['orders'] });
    },
    // A conflict means the order on screen is out of date; show the current one.
    onError: (error) => {
      if (error?.status === 409) queryClient.invalidateQueries({ queryKey: ['order', id] });
    },
  });
}
