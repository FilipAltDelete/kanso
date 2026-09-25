import { expect, test as base } from '@playwright/test';

/**
 * Every test signs in on its own. The refresh token rotates on every use
 * (docs/adr/0002), so a session saved once and shared by parallel tests is
 * spent by the first one to reload: the others would find themselves signed
 * out. Signing in through the API puts a fresh refresh cookie in this test's
 * browser context, and the app picks it up on load.
 */
export const test = base.extend({
  page: async ({ page }, use) => {
    await signIn(page.context());
    await use(page);
  },
});

export async function signIn(context) {
  const response = await context.request.post('/api/auth/login', {
    data: { email: process.env.E2E_USER || 'admin', password: process.env.E2E_PASSWORD || 'admin' },
  });
  expect(response.ok(), `sign-in failed: ${response.status()}`).toBeTruthy();
}

export { expect };
