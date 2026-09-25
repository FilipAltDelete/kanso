import { expect, test } from './support/fixtures.js';

test('the signed-in shell opens on the dashboard', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});
