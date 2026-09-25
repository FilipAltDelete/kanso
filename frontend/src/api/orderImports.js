import { useMutation, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

export const orderImportResultSchema = z.object({
  dryRun: z.boolean(),
  rows: z.number().int(),
  orders: z.number().int(),
  created: z.number().int(),
  existing: z.number().int(),
  failed: z.number().int(),
  errors: z.array(z.object({ row: z.number().int(), reference: z.string().nullable(), field: z.string(), code: z.string(), message: z.string() })),
});

/** `{ file, dryRun }`: a dry run is the preview, and writes nothing (ADR-0008). */
export function useImportOrders() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ file, dryRun }) =>
      orderImportResultSchema.parse(await api(`/api/order-imports?dryRun=${dryRun ? 'true' : 'false'}`, { method: 'POST', body: file, headers: { 'Content-Type': 'text/csv' } })),
    onSuccess: (result) => {
      if (!result.dryRun) queryClient.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}
