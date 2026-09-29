import { currentUserSchema, tokenResponseSchema } from './schemas.js';

/**
 * The access token lives here and nowhere else — a module-scoped variable, never
 * localStorage, so a reload goes through a silent refresh and nothing that can
 * read storage can read the session.
 */
let accessToken = null;
let refreshInFlight = null;
const sessionEndedListeners = new Set();

export function setAccessToken(token) {
  accessToken = token;
}

/**
 * `listener()` runs when the session ends under the app's feet: a request
 * was refused for an expired access token, and the refresh token was refused
 * too (ADR-0020). Once per ending, however many requests found out together.
 * Returns the unsubscribe function.
 */
export function onSessionEnded(listener) {
  sessionEndedListeners.add(listener);
  return () => sessionEndedListeners.delete(listener);
}

export class ApiError extends Error {
  constructor(status, problem) {
    // For developers; what the person reads comes from lib/errorMessage.js.
    super(problem?.detail ?? problem?.title ?? `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.problem = problem ?? {};
    this.violations = problem?.violations ?? [];
  }
}

/** The request got no answer at all: offline, or the proxy is down. */
export class NetworkError extends Error {
  constructor(cause) {
    super('No response from the server.', { cause });
    this.name = 'NetworkError';
  }
}

/** `fetch`, with a failure to get any answer as a NetworkError. A cancelled request stays an AbortError. */
async function send(path, init) {
  try {
    return await fetch(path, init);
  } catch (error) {
    throw error?.name === 'AbortError' ? error : new NetworkError(error);
  }
}

async function readProblem(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

/**
 * Answers that say nothing about the refresh token: the server was busy or
 * failing. Anything else it answers with (401 above all) refuses the token,
 * and the session is over.
 */
function isTransient(status) {
  return status === 408 || status === 429 || status >= 500;
}

/**
 * Runs `task` while no other window of the app is refreshing. Two windows
 * refreshing at once would both send the same cookie, and the refresh token
 * the second one sends is spent by then: the server takes a spent token for a
 * stolen one and ends the session (ADR-0019). Taking turns, the second sends
 * the rotated cookie. Where the Web Locks API is missing, the windows take
 * their chances.
 */
function oneWindowAtATime(task) {
  return typeof navigator !== 'undefined' && navigator.locks?.request ? navigator.locks.request('kanso.auth.refresh', task) : task();
}

/** One refresh at a time, however many requests hit 401 together. */
function refreshAccessToken() {
  refreshInFlight ??= oneWindowAtATime(() =>
    send('/api/auth/refresh', { method: 'POST', credentials: 'same-origin' }).then(async (response) => {
      if (!response.ok) throw new ApiError(response.status, await readProblem(response));
      const body = tokenResponseSchema.parse(await response.json());
      setAccessToken(body.accessToken);
      return body.accessToken;
    }),
  ).finally(() => {
    refreshInFlight = null;
  });

  return refreshInFlight;
}

function endSession() {
  // The first request to learn of it tells everyone; the rest find no token.
  if (accessToken === null) return;
  setAccessToken(null);
  for (const listener of sessionEndedListeners) listener();
}

/**
 * A JSON body is serialized; a Blob (a File the user picked) is sent as it
 * is, with its own type unless `headers` names one.
 */
export async function api(path, { method = 'GET', body, headers = {}, signal, allowRetry = true } = {}) {
  const raw = body instanceof Blob;
  const sentWith = accessToken;
  const response = await send(path, {
    method,
    signal,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/ld+json, application/json',
      ...(body === undefined ? {} : { 'Content-Type': raw ? body.type || 'application/octet-stream' : 'application/json' }),
      ...(sentWith ? { Authorization: `Bearer ${sentWith}` } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : raw ? body : JSON.stringify(body),
  });

  if (response.status === 401 && allowRetry && sentWith !== null) {
    // The access token is short-lived by design: renew it once, then retry.
    // A request that went out with a token another one has since renewed
    // retries with the new token; renewing again would only rotate the
    // refresh token for nothing.
    if (accessToken === sentWith) {
      try {
        await refreshAccessToken();
      } catch (error) {
        // Unreachable or busy: the refresh token may well be good, so the
        // session stays and the next request tries again.
        if (!(error instanceof ApiError) || isTransient(error.status)) throw error;
        endSession();
        throw new ApiError(401, await readProblem(response));
      }
    } else if (accessToken === null) {
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

  /**
   * Ends the session the refresh cookie belongs to. Sent without the access
   * token: the endpoint needs none, and one that has expired meanwhile would
   * only get the sign-out refused.
   */
  async logout() {
    try {
      const response = await send('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
      if (!response.ok) throw new ApiError(response.status, await readProblem(response));
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
