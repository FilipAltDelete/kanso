import { expect, test } from '@playwright/test';
import { front, open, uniqueTag } from './support/app.js';

/**
 * What the proxy adds to and keeps from every response (docker/proxy/,
 * ADR-0018), checked through e2e-proxy: the same image and strict policy an
 * installation runs. The e2e proxy trusts this network as it would a TLS
 * terminator, so a spec can say that a request arrived over HTTPS.
 */

const API_POLICY = "default-src 'none'; frame-ancestors 'none'";
const HSTS = 'max-age=31536000';
const OVER_TLS = { 'X-Forwarded-Proto': 'https', 'X-Forwarded-For': '198.51.100.23' };

test('the app is served with the security headers and a strict policy', async ({ request }) => {
  // The entry point, and a route the single-page app answers.
  for (const path of ['/', '/orders']) {
    const response = await request.get(path);
    expect(response.status()).toBe(200);
    expectSecurityHeaders(response);

    const policy = directives(only(response, 'content-security-policy'));
    expect(policy['default-src']).toEqual(["'self'"]);
    expect(policy['script-src']).toEqual(["'self'"]);
    expect(policy['style-src']).toEqual(["'self'"]);
    expect(policy['connect-src']).toEqual(["'self'"]);
    expect(policy['object-src']).toEqual(["'none'"]);
    expect(policy['base-uri']).toEqual(["'none'"]);
    expect(policy['frame-ancestors']).toEqual(["'none'"]);
    expect(Object.values(policy).flat()).not.toContain("'unsafe-inline'");
    expect(Object.values(policy).flat()).not.toContain("'unsafe-eval'");
  }
});

test('the bundle is served compressed', async ({ request }) => {
  const html = await (await request.get('/')).text();
  const script = html.match(/<script[^>]+src="([^"]+\.js)"/)?.[1];
  expect(script, 'no script in index.html').toBeTruthy();

  const response = await request.get(script, { headers: { 'Accept-Encoding': 'gzip' } });
  expect(response.status()).toBe(200);
  expect(response.headers()['content-encoding']).toBe('gzip');
  expect(only(response, 'x-content-type-options')).toBe('nosniff');
});

test('API responses carry them too, errors included, with a policy that allows nothing', async ({ request }) => {
  const refused = await request.get('/api/auth/me');
  expect(refused.status()).toBe(401);
  expectSecurityHeaders(refused);
  expect(only(refused, 'content-security-policy')).toBe(API_POLICY);

  const login = await signIn(request);
  expectSecurityHeaders(login);
  expect(only(login, 'content-security-policy')).toBe(API_POLICY);

  // API Platform sends its own X-Content-Type-Options; the proxy's replaces it.
  const orders = await request.get('/api/orders', { headers: { Authorization: `Bearer ${(await login.json()).accessToken}` } });
  expect(orders.status()).toBe(200);
  expectSecurityHeaders(orders);
  expect(only(orders, 'content-security-policy')).toBe(API_POLICY);
  expect(all(orders, 'x-powered-by')).toEqual([]);
});

test('metrics and readiness are not reachable through the proxy', async ({ request }) => {
  for (const path of ['/metrics', '/metrics/', '/metrics?format=prometheus']) {
    const response = await request.get(path);
    expect(await response.text(), path).not.toMatch(/^# (HELP|TYPE) /m);
  }
  expect((await request.get('/metrics')).status()).toBe(404);

  // Readiness says which dependency failed and why: for the platform only.
  expect((await request.get('/health/ready')).status()).toBe(404);
  expect((await request.get('/health/live')).status()).toBe(200);
});

test('behind a TLS terminator: HSTS and a Secure refresh cookie', async ({ request }) => {
  const page = await request.get('/', { headers: OVER_TLS });
  expect(only(page, 'strict-transport-security')).toBe(HSTS);

  // The API is told the scheme, so the cookie it sets is Secure.
  const overTls = await signIn(request, OVER_TLS);
  expect(only(overTls, 'strict-transport-security')).toBe(HSTS);
  expect(refreshCookie(overTls)).toMatch(/;\s*secure/i);

  const plain = await signIn(request);
  expect(all(plain, 'strict-transport-security')).toEqual([]);
  expect(refreshCookie(plain)).not.toMatch(/;\s*secure/i);
});

test('no policy violations: sign in, the order list, an order and its pick list', async ({ page, request }) => {
  test.setTimeout(90_000);
  const violations = await watchViolations(page);
  const order = await createOrder(request);

  await page.goto('/');
  await page.getByLabel('Email').fill(process.env.E2E_USER || 'admin');
  await page.getByLabel('Password').fill(process.env.E2E_PASSWORD || 'admin');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();

  const list = await open(page, '/orders');
  await expect(list.getByRole('grid')).toBeVisible();
  await expect(list.getByRole('row').nth(1)).toBeVisible();

  const detail = await open(page, `/orders/${order.id}`);
  await expect(detail.getByText(order.number).first()).toBeVisible();

  // The PDF is a link to object storage (a presigned URL on another origin),
  // opened in a new tab: a navigation, which the app's policy does not govern.
  await detail.getByRole('button', { name: 'Pick list', exact: true }).click();
  const link = front(page).getByRole('link', { name: 'Open pick list (PDF)' });
  await expect(link).toBeVisible({ timeout: 60_000 });
  const href = new URL(await link.getAttribute('href'));
  expect(href.origin).not.toBe(new URL(page.url()).origin);
  const [pdf] = await Promise.all([page.context().waitForEvent('request', (r) => r.url().startsWith(href.origin)), link.click()]);
  expect(pdf.url()).toBe(href.toString());

  expect(violations).toEqual([]);
});

/** Records every CSP or Permissions-Policy complaint the page makes. */
async function watchViolations(page) {
  const violations = [];
  page.on('console', (message) => {
    if (/Content Security Policy|Permissions-Policy/i.test(message.text())) violations.push(message.text());
  });
  // Chromium logs them itself; the event also names the directive.
  await page.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
      console.error(`Content Security Policy violation: ${event.violatedDirective} blocked ${event.blockedURI}`);
    });
  });

  return violations;
}

function expectSecurityHeaders(response) {
  expect(all(response, 'content-security-policy')).toHaveLength(1);
  expect(only(response, 'x-content-type-options')).toBe('nosniff');
  expect(only(response, 'referrer-policy')).toBe('same-origin');
  expect(only(response, 'permissions-policy')).toContain('camera=()');
  // Plain HTTP: HSTS would pin a host that has no TLS.
  expect(all(response, 'strict-transport-security')).toEqual([]);
}

/** Every value of a header, however many times it was sent. */
function all(response, name) {
  return response
    .headersArray()
    .filter((header) => header.name.toLowerCase() === name)
    .map((header) => header.value);
}

/** The header's value, which must have been sent exactly once. */
function only(response, name) {
  const values = all(response, name);
  expect(values, `${name} sent ${values.length} times`).toHaveLength(1);

  return values[0];
}

function directives(policy) {
  return Object.fromEntries(
    policy
      .split(';')
      .map((directive) => directive.trim().split(/\s+/))
      .filter(([name]) => name)
      .map(([name, ...sources]) => [name, sources]),
  );
}

function refreshCookie(response) {
  return all(response, 'set-cookie').find((cookie) => cookie.startsWith('kanso_refresh=')) ?? '';
}

async function signIn(request, headers = {}) {
  const response = await request.post('/api/auth/login', {
    headers,
    data: { email: process.env.E2E_USER || 'admin', password: process.env.E2E_PASSWORD || 'admin' },
  });
  expect(response.ok(), `sign-in failed: ${response.status()}`).toBeTruthy();

  return response;
}

/** A pending order of one line, at a location and for a product of its own. */
async function createOrder(request) {
  const tag = uniqueTag();
  const token = (await (await signIn(request)).json()).accessToken;
  const post = async (path, data) => {
    const response = await request.post(path, { headers: { Authorization: `Bearer ${token}` }, data });
    expect(response.ok(), `${path}: ${response.status()} ${await response.text()}`).toBeTruthy();

    return response.json();
  };

  await post('/api/locations', { code: `SEC-${tag}`, name: `Security warehouse ${tag}` });
  await post('/api/products', { sku: `SEC-${tag}`, name: `Security tee ${tag}` });

  return post('/api/orders', {
    channel: 'manual',
    location: `SEC-${tag}`,
    customer: { name: `Security customer ${tag}` },
    shippingAddress: { line1: 'Storgatan 1', postalCode: '111 22', city: 'Stockholm', countryCode: 'SE' },
    lines: [{ sku: `SEC-${tag}`, quantity: 1, unitPrice: 19950 }],
  });
}
