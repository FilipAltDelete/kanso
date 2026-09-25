import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { api, auth } from './client.js';
import { listQuery } from './inventory.js';

/** Highest first; each includes the ones below it. */
export const USER_ROLES = ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_VIEWER'];

/** As the server checks it (PasswordPolicy). */
export const PASSWORD_MIN = 8;

export const userSchema = z.object({
  id: z.string(),
  email: z.string(),
  name: z.string().nullable(),
  role: z.enum(USER_ROLES),
  status: z.enum(['active', 'deactivated']),
  createdAt: z.string(),
});

/** Every user, deactivated ones included unless `status` says otherwise; `view` searches, sorts and pages it. */
export function useUsers(view, status) {
  const query = listQuery(view, status ? { status } : {});

  return useQuery({
    queryKey: ['users', query],
    queryFn: async ({ signal }) => z.object({ member: z.array(userSchema), totalItems: z.number().int() }).parse(await api(`/api/users?${query}`, { signal })),
    placeholderData: keepPreviousData,
  });
}

function useUserMutation(mutationFn) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (variables) => userSchema.parse(await mutationFn(variables)),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
  });
}

const userPath = (id, action = '') => `/api/users/${encodeURIComponent(id)}${action}`;

export function useCreateUser() {
  return useUserMutation((body) => api('/api/users', { method: 'POST', body }));
}

/** `{ id, ...changes }`: what is left out stays. */
export function useUpdateUser() {
  return useUserMutation(({ id, ...body }) => api(userPath(id), { method: 'PATCH', body, headers: { 'Content-Type': 'application/merge-patch+json' } }));
}

export function useDeactivateUser() {
  return useUserMutation((id) => api(userPath(id, '/deactivate'), { method: 'POST' }));
}

export function useActivateUser() {
  return useUserMutation((id) => api(userPath(id, '/activate'), { method: 'POST' }));
}

/** An admin sets someone else's password: `{ id, password }`. */
export function useSetUserPassword() {
  return useUserMutation(({ id, password }) => api(userPath(id, '/password'), { method: 'POST', body: { password } }));
}

/** The signed-in user's own password; this session goes on with the new tokens. */
export function useChangeOwnPassword() {
  return useMutation({
    mutationFn: ({ currentPassword, newPassword }) => auth.changePassword(currentPassword, newPassword),
  });
}
