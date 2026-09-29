import { ApiError, NetworkError, api, auth, onSessionEnded, setAccessToken } from './client.js';

/** A fetch answer, as much of one as the client reads. */
function answer(status, body = null) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

const tokens = (accessToken) => answer(200, { accessToken, expiresIn: 300, tokenType: 'Bearer' });

/** A promise and the functions that settle it, for answers that arrive when the test says. */
function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}

const isRefresh = (url) => url === '/api/auth/refresh';
const bearer = (init) => init.headers?.Authorization ?? null;
const calls = () => fetch.mock.calls.map(([url, init]) => [url, bearer(init)]);

/**
 * A server whose resources accept `valid` and refuse any other token with
 * 401, and whose refresh endpoint answers with `refresh()` — a promise, so a
 * test can hold it open while other requests arrive.
 */
function server({ valid, refresh }) {
  fetch.mockImplementation((url, init) => {
    if (isRefresh(url)) return refresh();
    return Promise.resolve(bearer(init) === `Bearer ${valid()}` ? answer(200, { url }) : answer(401, { title: 'Unauthorized' }));
  });
}

let unsubscribe = () => {};

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
  setAccessToken(null);
});

afterEach(() => {
  unsubscribe();
  vi.unstubAllGlobals();
});

describe('the API client', () => {
  it('sends the access token and parses the answer', async () => {
    setAccessToken('t1');
    fetch.mockResolvedValue(answer(200, { ok: true }));

    expect(await api('/api/orders')).toEqual({ ok: true });
    expect(calls()).toEqual([['/api/orders', 'Bearer t1']]);
  });

  it('refreshes once for every request that finds the token expired, then retries each with the new one', async () => {
    setAccessToken('old');
    let valid = 'new';
    const refresh = deferred();
    server({ valid: () => valid, refresh: () => refresh.promise });

    const requests = [api('/api/a'), api('/api/b'), api('/api/c')];
    // All three have been refused and are waiting on the one refresh.
    await vi.waitFor(() => expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(1));
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(4));
    refresh.resolve(tokens('new'));

    expect(await Promise.all(requests)).toEqual([{ url: '/api/a' }, { url: '/api/b' }, { url: '/api/c' }]);
    expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(1);
    expect(calls().slice(4)).toEqual([
      ['/api/a', 'Bearer new'],
      ['/api/b', 'Bearer new'],
      ['/api/c', 'Bearer new'],
    ]);

    // A later expiry starts a refresh of its own: the one before is over.
    valid = 'newer';
    server({ valid: () => valid, refresh: () => Promise.resolve(tokens('newer')) });
    expect(await api('/api/d')).toEqual({ url: '/api/d' });
    expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(2);
  });

  it('retries with a token another request has renewed meanwhile, without refreshing again', async () => {
    setAccessToken('old');
    const slow = deferred();
    fetch.mockImplementation((url, init) => {
      if (isRefresh(url)) return Promise.resolve(tokens('new'));
      if (url === '/api/slow' && bearer(init) === 'Bearer old') return slow.promise;
      return Promise.resolve(bearer(init) === 'Bearer new' ? answer(200, { url }) : answer(401));
    });

    const late = api('/api/slow');
    // Another request expires, refreshes and succeeds while the slow one is out.
    expect(await api('/api/fast')).toEqual({ url: '/api/fast' });
    slow.resolve(answer(401));

    expect(await late).toEqual({ url: '/api/slow' });
    expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(1);
  });

  it('ends the session once when the refresh token is refused, and every waiting request fails with 401', async () => {
    setAccessToken('old');
    const ended = vi.fn();
    unsubscribe = onSessionEnded(ended);
    const refresh = deferred();
    server({ valid: () => 'never', refresh: () => refresh.promise });

    const requests = [api('/api/a'), api('/api/b')].map((request) => request.catch((error) => error));
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
    refresh.resolve(answer(401, { title: 'Unauthorized', detail: 'The session has expired. Sign in again.' }));

    const errors = await Promise.all(requests);
    for (const error of errors) {
      expect(error).toBeInstanceOf(ApiError);
      expect(error.status).toBe(401);
    }
    expect(ended).toHaveBeenCalledTimes(1);

    // No token is left to send, and none is refreshed.
    fetch.mockClear();
    fetch.mockResolvedValue(answer(401));
    await expect(api('/api/c')).rejects.toMatchObject({ status: 401 });
    expect(calls()).toEqual([['/api/c', null]]);
  });

  it('keeps the session when the refresh cannot get an answer, or the server is busy', async () => {
    setAccessToken('old');
    const ended = vi.fn();
    unsubscribe = onSessionEnded(ended);

    server({ valid: () => 'new', refresh: () => Promise.reject(new TypeError('Failed to fetch')) });
    await expect(api('/api/a')).rejects.toBeInstanceOf(NetworkError);

    server({ valid: () => 'new', refresh: () => Promise.resolve(answer(429, { title: 'Too Many Requests' })) });
    await expect(api('/api/a')).rejects.toMatchObject({ status: 429 });

    server({ valid: () => 'new', refresh: () => Promise.resolve(answer(503)) });
    await expect(api('/api/a')).rejects.toMatchObject({ status: 503 });

    expect(ended).not.toHaveBeenCalled();

    // Still signed in: once the server answers again, the refresh goes through.
    server({ valid: () => 'new', refresh: () => Promise.resolve(tokens('new')) });
    expect(await api('/api/a')).toEqual({ url: '/api/a' });
  });

  it('retries once only: a refused retry is the answer', async () => {
    setAccessToken('old');
    const ended = vi.fn();
    unsubscribe = onSessionEnded(ended);
    server({ valid: () => 'never', refresh: () => Promise.resolve(tokens('new')) });

    await expect(api('/api/a')).rejects.toMatchObject({ status: 401 });
    expect(calls()).toEqual([
      ['/api/a', 'Bearer old'],
      ['/api/auth/refresh', null],
      ['/api/a', 'Bearer new'],
    ]);
    expect(ended).not.toHaveBeenCalled();
  });

  it('shares a refresh under way with the one a page load starts', async () => {
    setAccessToken('old');
    const refresh = deferred();
    server({ valid: () => 'new', refresh: () => refresh.promise });

    const request = api('/api/a');
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    const restored = auth.restore();
    refresh.resolve(tokens('new'));

    expect(await restored).toBe('new');
    expect(await request).toEqual({ url: '/api/a' });
    expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(1);
  });

  it('takes turns with the other windows of the app to refresh', async () => {
    const lock = vi.fn((name, task) => task());
    vi.stubGlobal('navigator', { ...navigator, locks: { request: lock } });
    fetch.mockResolvedValue(tokens('new'));

    await auth.restore();

    expect(lock).toHaveBeenCalledWith('kanso.auth.refresh', expect.any(Function));
  });

  it('does not refresh for a request that had no token, nor for one that asked not to', async () => {
    fetch.mockResolvedValue(answer(401, { title: 'Unauthorized' }));
    await expect(api('/api/auth/me')).rejects.toMatchObject({ status: 401 });

    setAccessToken('t1');
    await expect(api('/api/auth/login', { method: 'POST', body: {}, allowRetry: false })).rejects.toMatchObject({ status: 401 });

    expect(fetch.mock.calls.filter(([url]) => isRefresh(url))).toHaveLength(0);
  });

  it('signs out by the refresh cookie alone, so an expired access token cannot stop it', async () => {
    setAccessToken('expired');
    fetch.mockResolvedValue(answer(204));

    await auth.logout();

    expect(calls()).toEqual([['/api/auth/logout', null]]);
    // Signed out here too: the next request carries no token.
    await api('/api/a');
    expect(calls()[1]).toEqual(['/api/a', null]);
  });

  it('leaves a cancelled request cancelled, and names one that got no answer', async () => {
    const aborted = Object.assign(new Error('aborted'), { name: 'AbortError' });
    fetch.mockRejectedValueOnce(aborted).mockRejectedValueOnce(new TypeError('Failed to fetch'));

    await expect(api('/api/a')).rejects.toBe(aborted);
    await expect(api('/api/a')).rejects.toBeInstanceOf(NetworkError);
  });
});
