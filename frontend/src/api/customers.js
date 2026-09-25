import { api } from './client.js';
import { customerEventPageSchema, customerPageSchema, customerSchema } from './schemas.js';

/** The list's sortable columns, as the API names them (`order[field]=asc|desc`). */
export const CUSTOMER_SORTS = ['name', 'email', 'createdAt', 'updatedAt'];

function listQuery({ q, sorting = [], page = 1, pageSize = 25 }) {
  const params = new URLSearchParams({ page: String(page), itemsPerPage: String(pageSize) });
  if (q) params.set('q', q);
  for (const { id, desc } of sorting) {
    if (CUSTOMER_SORTS.includes(id)) params.set(`order[${id}]`, desc ? 'desc' : 'asc');
  }

  return params.toString();
}

export const customers = {
  async list(options, signal) {
    return customerPageSchema.parse(await api(`/api/customers?${listQuery(options)}`, { signal }));
  },

  async get(id, signal) {
    return customerSchema.parse(await api(`/api/customers/${encodeURIComponent(id)}`, { signal }));
  },

  async create(customer) {
    return customerSchema.parse(await api('/api/customers', { method: 'POST', body: customer }));
  },

  /** A merge patch: send only what changes; `addresses`, when sent, is the whole list. */
  async update(id, changes) {
    return customerSchema.parse(
      await api(`/api/customers/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        body: changes,
        headers: { 'Content-Type': 'application/merge-patch+json' },
      }),
    );
  },

  async history(id, { page = 1, pageSize = 50 } = {}, signal) {
    const params = new URLSearchParams({ page: String(page), itemsPerPage: String(pageSize) });

    return customerEventPageSchema.parse(await api(`/api/customers/${encodeURIComponent(id)}/history?${params}`, { signal }));
  },
};
