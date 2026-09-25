import { expect, test } from './support/fixtures.js';
import { createLocation, createProduct, open, uniqueTag } from './support/app.js';

function csv(name, rows) {
  return { name, mimeType: 'text/csv', buffer: Buffer.from(rows.join('\n') + '\n', 'utf8') };
}

/** Upload, read the preview, import; then the same file again changes nothing. */
test('products from CSV: preview with the failing row, import, and the same file twice', async ({ page }) => {
  const tag = uniqueTag();
  const file = csv(`products-${tag}.csv`, ['sku,name,weightGrams', `E2E-CSV-${tag},E2E csv tee ${tag},180`, `E2E-BAD-${tag},E2E bad ${tag},heavy`]);

  let pane = await open(page, '/products/import');
  await pane.locator('input[type=file]').setInputFiles(file);
  await expect(pane.getByText('Preview: nothing has been imported yet')).toBeVisible();
  await expect(pane.getByText(`E2E-BAD-${tag}`)).toBeVisible();
  await pane.getByRole('button', { name: 'Import 1 products' }).click();
  await expect(pane.getByText('Import finished')).toBeVisible();

  pane = await open(page, '/products/import');
  await pane.locator('input[type=file]').setInputFiles(file);
  await expect(pane.getByText('Preview: nothing has been imported yet')).toBeVisible();
  await expect(pane.getByRole('button', { name: 'Nothing to import' })).toBeDisabled();

  pane = await open(page, '/products');
  await pane.getByRole('searchbox').fill(`E2E-CSV-${tag}`);
  await expect(pane.getByRole('link', { name: `E2E-CSV-${tag}` })).toBeVisible();
});

test('orders from CSV: an order with a problem is skipped whole, and a second import creates nothing', async ({ page }) => {
  const tag = uniqueTag();
  const location = `E2E-${tag}`;
  const sku = `E2E-ORD-${tag}`;
  await createLocation(page, location, `E2E warehouse ${tag}`);
  await createProduct(page, sku, `E2E import tee ${tag}`);

  const header = 'orderReference,customerName,shippingLine1,shippingPostalCode,shippingCity,shippingCountry,location,sku,quantity,unitPrice';
  const file = csv(`orders-${tag}.csv`, [
    header,
    `E2E-${tag}-1,E2E customer,Storgatan 1,111 22,Stockholm,SE,${location},${sku},2,199.00`,
    `E2E-${tag}-1,,,,,,,${sku},1,199.00`,
    `E2E-${tag}-2,E2E customer,Storgatan 1,111 22,Stockholm,SE,${location},NO-SUCH-SKU-${tag},1,99.00`,
  ]);

  let pane = await open(page, '/orders/import');
  await pane.locator('input[type=file]').setInputFiles(file);
  await expect(pane.getByText('Preview: nothing has been imported yet')).toBeVisible();
  await expect(pane.getByText(`E2E-${tag}-2`).first()).toBeVisible();
  await pane.getByRole('button', { name: 'Import 1 orders' }).click();
  await expect(pane.getByText('Import finished')).toBeVisible();

  pane = await open(page, '/orders/import');
  await pane.locator('input[type=file]').setInputFiles(file);
  await expect(pane.getByText('Preview: nothing has been imported yet')).toBeVisible();
  await expect(pane.getByRole('button', { name: 'Nothing to import' })).toBeDisabled();
});
