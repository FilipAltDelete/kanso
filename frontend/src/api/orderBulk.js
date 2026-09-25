import { useMutation, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

/** The transitions the order list offers in bulk, in lifecycle order; shipping goes through shipments (ADR-0015). */
export const BULK_TRANSITIONS = ['confirm', 'allocate', 'start_picking', 'pack', 'deliver', 'hold', 'release', 'cancel'];

export const bulkTransitionResultSchema = z.object({
  transition: z.string(),
  moved: z.array(z.object({ id: z.string(), number: z.string(), status: z.string(), version: z.number().int() })),
  failed: z.array(z.object({ id: z.string(), number: z.string().nullable(), code: z.string(), message: z.string() })),
});

/**
 * `{ transition, orders: [{ id, version? }] }`. Each order moves on its own;
 * the result says which did and why the others did not. The list and every
 * moved order refetch either way.
 */
export function useBulkTransition() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ transition, orders }) =>
      bulkTransitionResultSchema.parse(await api('/api/orders/bulk-transitions', { method: 'POST', body: { transition, orders } })),
    onSuccess: (result) => {
      for (const { id } of result.moved) queryClient.invalidateQueries({ queryKey: ['order', id] });
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['orders'] }),
  });
}
