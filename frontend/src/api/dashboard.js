import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';
import { ORDER_STATUSES } from './orders.js';

export const dashboardSchema = z.object({
  date: z.string(),
  timeZone: z.string(),
  dayStart: z.string(),
  dayEnd: z.string(),
  ordersToday: z.number().int(),
  awaitingFulfillment: z.number().int(),
  shippedToday: z.number().int(),
  ordersByStatus: z.object(Object.fromEntries(ORDER_STATUSES.map((status) => [status, z.number().int()]))),
  stockOuts: z.object({
    count: z.number().int(),
    items: z.array(
      z.object({
        productId: z.string(),
        sku: z.string(),
        productName: z.string(),
        locationId: z.string(),
        locationCode: z.string(),
        locationName: z.string(),
        onHand: z.number().int(),
        reserved: z.number().int(),
      }),
    ),
  }),
  generatedAt: z.string(),
});

/** How often the numbers are counted again while the dashboard is open. */
export const DASHBOARD_REFRESH_MS = 30_000;

/** The browser's time zone, so "today" is the operator's day, not UTC's. */
function timeZone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC';
  } catch {
    return 'UTC';
  }
}

export function useDashboard() {
  const zone = timeZone();

  return useQuery({
    queryKey: ['dashboard', zone],
    queryFn: async ({ signal }) => dashboardSchema.parse(await api(`/api/dashboard?timeZone=${encodeURIComponent(zone)}`, { signal })),
    refetchInterval: DASHBOARD_REFRESH_MS,
  });
}
