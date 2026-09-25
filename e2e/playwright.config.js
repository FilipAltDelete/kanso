import { defineConfig, devices } from '@playwright/test';

/**
 * The main operator flows, clicked through in Chromium against a running
 * installation (`make e2e` runs them in the Playwright image against the
 * compose stack). Every spec makes its own uniquely named data, so the suite
 * can run against a development installation that already has some.
 */
export default defineConfig({
  testDir: './tests',
  // One installation, one database: specs share stock levels only through
  // data they created themselves, so they can run in parallel.
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://proxy:8080',
    // The UI follows the browser's language; the specs read English.
    locale: 'en-GB',
    timezoneId: 'Europe/Stockholm',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  // Each test signs in for itself (tests/support/fixtures.js).
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
