import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from './client.js';
import { listQuery } from './inventory.js';

/** The roles a key can have; never admin. */
export const API_KEY_ROLES = ['ROLE_OPERATOR', 'ROLE_VIEWER'];

export const apiKeySchema = z.object({
  id: z.string(),
  name: z.string(),
  role: z.string(),
  status: z.enum(['active', 'expired', 'revoked']),
  createdBy: z.string().nullable(),
  createdByName: z.string().nullable(),
  createdAt: z.string(),
  expiresAt: z.string().nullable(),
  lastUsedAt: z.string().nullable(),
  revokedAt: z.string().nullable(),
});

/** The one answer that carries the key itself. */
const createdApiKeySchema = apiKeySchema.extend({ key: z.string() });

/** Every key, revoked and expired ones included; `view` searches, sorts and pages it. */
export function useApiKeys(view) {
  const query = listQuery(view);

  return useQuery({
    queryKey: ['apiKeys', query],
    queryFn: async ({ signal }) =>
      z.object({ member: z.array(apiKeySchema), totalItems: z.number().int() }).parse(await api(`/api/api-keys?${query}`, { signal })),
    placeholderData: keepPreviousData,
  });
}

/** Resolves with the new key, `key` included: show it now, it cannot be read again. */
export function useCreateApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body) => createdApiKeySchema.parse(await api('/api/api-keys', { method: 'POST', body })),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['apiKeys'] }),
  });
}

export function useRevokeApiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id) => apiKeySchema.parse(await api(`/api/api-keys/${encodeURIComponent(id)}/revoke`, { method: 'POST' })),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['apiKeys'] }),
  });
}
