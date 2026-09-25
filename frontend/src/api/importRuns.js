import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';

const errorSchema = z.object({
  row: z.number().int(),
  sku: z.string().nullish(),
  reference: z.string().nullish(),
  field: z.string(),
  code: z.string(),
  message: z.string(),
});

export const importRunSchema = z.object({
  id: z.string(),
  type: z.enum(['products', 'orders']),
  filename: z.string().nullable(),
  actorId: z.string(),
  actorName: z.string(),
  counts: z.record(z.string(), z.number().int()),
  errorCount: z.number().int(),
  errors: z.array(errorSchema).optional(),
  startedAt: z.string(),
});

/** The imports of one kind that were run for real (ADR-0014), newest first; `view` pages it. */
export function useImportRuns(type, view) {
  const query = new URLSearchParams({ type, page: String(view.pageIndex + 1), itemsPerPage: String(view.pageSize) }).toString();

  return useQuery({
    queryKey: ['importRuns', query],
    queryFn: async ({ signal }) =>
      z.object({ member: z.array(importRunSchema), totalItems: z.number().int() }).parse(await api(`/api/import-runs?${query}`, { signal })),
    placeholderData: keepPreviousData,
  });
}

/** One run with the rows that failed. */
export function useImportRun(id) {
  return useQuery({
    queryKey: ['importRun', id],
    queryFn: async ({ signal }) => importRunSchema.parse(await api(`/api/import-runs/${encodeURIComponent(id)}`, { signal })),
    // A run is written once and never changes.
    staleTime: Infinity,
  });
}

/** An import's query string: the preview flag, and the file's name for the history. */
export function importQuery(file, dryRun) {
  const params = new URLSearchParams({ dryRun: dryRun ? 'true' : 'false' });
  if (file.name) params.set('filename', file.name);

  return params.toString();
}
