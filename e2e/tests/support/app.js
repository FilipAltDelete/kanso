import { expect } from '@playwright/test';

/** A short random tag, so every run makes its own products, locations and orders. */
export function uniqueTag() {
  return Math.random().toString(36).slice(2, 8).toUpperCase();
}

/**
 * The page in the tab in front. With tabs and split panes, other pages can be
 * rendered too; a spec reads and clicks the one the operator is looking at.
 */
export function front(page) {
  return page.locator('[data-front-tab]');
}

/** Opens a page by its address, as a bookmark or a pasted link would. */
export async function open(page, path) {
  await page.goto(path);
  await expect(front(page)).toBeVisible();

  return front(page);
}

/** Creates a location through the Locations page's dialog. */
export async function createLocation(page, code, name) {
  const pane = await open(page, '/locations');
  await pane.getByRole('button', { name: 'New location' }).click();
  const dialog = page.getByRole('dialog', { name: 'New location' });
  await dialog.getByLabel(/^Location/).fill(code);
  await dialog.getByLabel(/^Name/).fill(name);
  await dialog.getByRole('button', { name: 'Create location' }).click();
  await expect(dialog).toBeHidden();
  await expect(pane.getByRole('gridcell', { name: code, exact: true })).toBeVisible();
}

/** Creates a product through the Products page's dialog; ends on the product's page. */
export async function createProduct(page, sku, name) {
  const pane = await open(page, '/products');
  await pane.getByRole('button', { name: 'New product' }).click();
  const dialog = page.getByRole('dialog', { name: 'New product' });
  await dialog.getByLabel(/^SKU/).fill(sku);
  await dialog.getByLabel(/^Name/).fill(name);
  await dialog.getByRole('button', { name: 'Create product' }).click();
  await expect(dialog).toBeHidden();
}
