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

export const customerAddressSchema = z.object({
  id: z.string().min(1),
  type: z.enum(['billing', 'shipping']),
  isDefault: z.boolean(),
  name: z.string().nullable(),
  company: z.string().nullable(),
  line1: z.string(),
  line2: z.string().nullable(),
  postalCode: z.string(),
  city: z.string(),
  region: z.string().nullable(),
  countryCode: z.string().length(2),
  phone: z.string().nullable(),
});

export const customerSchema = z.object({
  id: z.string().min(1),
  email: z.string(),
  name: z.string(),
  phone: z.string().nullable(),
  addresses: z.array(customerAddressSchema),
  createdAt: z.string(),
  updatedAt: z.string(),
});

/** A JSON-LD collection page: the items and the total across all pages. */
export function pageSchema(item) {
  return z.object({ member: z.array(item), totalItems: z.number().int().nonnegative() });
}

export const customerPageSchema = pageSchema(customerSchema);

export const customerEventSchema = z.object({
  id: z.string().min(1),
  type: z.enum(['created', 'updated']),
  actor: z.string(),
  changes: z.record(z.object({ before: z.unknown(), after: z.unknown() })),
  occurredAt: z.string(),
});

export const customerEventPageSchema = pageSchema(customerEventSchema);
