import { z } from 'zod';

/** The API's shapes, checked where responses enter the app (no TypeScript, as in Pimsen). */
export const tokenResponseSchema = z.object({
  accessToken: z.string().min(1),
  expiresIn: z.number().int().positive(),
  tokenType: z.literal('Bearer'),
});

export const currentUserSchema = z.object({
  id: z.string().min(1),
  email: z.string().min(1),
  name: z.string().nullable(),
  roles: z.array(z.string()),
});
