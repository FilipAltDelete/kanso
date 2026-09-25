import { useMutation, useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

export const DOCUMENT_TYPES = ['pick_list', 'packing_slip'];

export const documentSchema = z.object({
  id: z.string(),
  type: z.enum(DOCUMENT_TYPES),
  orderId: z.string(),
  orderNumber: z.string(),
  orderVersion: z.number().int(),
  locale: z.string(),
  status: z.enum(['queued', 'running', 'done', 'failed']),
  filename: z.string(),
  downloadUrl: z.string().nullable(),
  byteSize: z.number().int().nullable(),
  createdAt: z.string(),
  completedAt: z.string().nullable(),
});

/** How often a document on its way is asked about. */
const POLL_MS = 1000;
/** A download link lasts five minutes; a fresh one is fetched before it runs out. */
const RELINK_MS = 4 * 60 * 1000;

/** Queue a pick list or packing slip for the order as it is now. */
export function useRequestDocument(orderId) {
  return useMutation({
    mutationFn: async ({ type, locale }) =>
      documentSchema.parse(await api(`/api/orders/${encodeURIComponent(orderId)}/documents`, { method: 'POST', body: { type, locale } })),
  });
}

/** A document, polled until it is done or failed, and kept with a working link while shown. */
export function useDocument(id) {
  return useQuery({
    queryKey: ['documents', id],
    queryFn: async ({ signal }) => documentSchema.parse(await api(`/api/documents/${encodeURIComponent(id)}`, { signal })),
    enabled: Boolean(id),
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      if (status === 'done') return RELINK_MS;

      return status === 'failed' ? false : POLL_MS;
    },
  });
}
