import { currentUserSchema, tokenResponseSchema } from './schemas.js';

/**
 * The access token lives here and nowhere else — a module-scoped variable, never
 * localStorage, so a reload goes through a silent refresh and nothing that can
 * read storage can read the session.
 */
let accessToken = null;
let refreshInFlight = null;

export function setAccessToken(token) {
  accessToken = token;
}

export class ApiError extends Error {
  constructor(status, problem) {
    super(problem?.detail ?? problem?.title ?? `Request failed with ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.problem = problem ?? {};
    this.violations = problem?.violations ?? [];
  }
}

async function readProblem(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

/** One refresh at a time, however many requests hit 401 together. */
function refreshAccessToken() {
  refreshInFlight ??= fetch('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' })
    .then(async (response) => {
      if (!response.ok) throw new ApiError(response.status, await readProblem(response));
      const body = tokenResponseSchema.parse(await response.json());
      setAccessToken(body.accessToken);
      return body.accessToken;
    })
    .finally(() => {
      refreshInFlight = null;
    });

  return refreshInFlight;
}

/**
 * A JSON body is serialized; a Blob (a File the user picked) is sent as it
 * is, with its own type unless `headers` names one.
 */
export async function api(path, { method = 'GET', body, headers = {}, signal, allowRetry = true } = {}) {
  const raw = body instanceof Blob;
  const response = await fetch(path, {
    method,
    signal,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/ld+json, application/json',
      ...(body === undefined ? {} : { 'Content-Type': raw ? body.type || 'application/octet-stream' : 'application/json' }),
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : raw ? body : JSON.stringify(body),
  });

  if (response.status === 401 && allowRetry && accessToken !== null) {
    // The access token is short-lived by design; renew once, then retry.
    try {
      await refreshAccessToken();
    } catch {
      setAccessToken(null);
      throw new ApiError(401, await readProblem(response));
    }
    return api(path, { method, body, headers, signal, allowRetry: false });
  }

  if (!response.ok) {
    throw new ApiError(response.status, await readProblem(response));
  }

  return response.status === 204 ? null : response.json();
}

export const auth = {
  async login(email, password) {
    const body = tokenResponseSchema.parse(
      await api('/api/auth/login', { method: 'POST', body: { email, password }, allowRetry: false }),
    );
    setAccessToken(body.accessToken);
    return body;
  },

  /** Called on every page load: the refresh cookie is the only thing that survives. */
  restore() {
    return refreshAccessToken();
  },

  async logout() {
    try {
      await api('/api/auth/logout', { method: 'POST', allowRetry: false });
    } finally {
      setAccessToken(null);
    }
  },

  /** Ends every other session; this one carries on with the tokens in the answer. */
  async changePassword(currentPassword, newPassword) {
    const body = tokenResponseSchema.parse(
      await api('/api/auth/password', { method: 'POST', body: { currentPassword, newPassword } }),
    );
    setAccessToken(body.accessToken);
    return body;
  },

  async me() {
    return currentUserSchema.parse(await api('/api/auth/me'));
  },
};
