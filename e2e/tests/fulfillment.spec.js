import { expect, test } from './support/fixtures.js';
import { createLocation, createProduct, front, open, uniqueTag } from './support/app.js';

/**
 * The order flow Phase 1 exists for, clicked through: stock a product,
 * take an order for it, confirm (stock is reserved), ship it in two parcels,
 * take back a parcel recorded by mistake, and read the numbers on the
 * product page after each step.
 */
test('stock → order → confirm → ship in parts → void a mistake', async ({ page }) => {
  const tag = uniqueTag();
  const location = `E2E-${tag}`;
  const sku = `E2E-TEE-${tag}`;

  await createLocation(page, location, `E2E warehouse ${tag}`);
  await createProduct(page, sku, `E2E tee ${tag}`);

  // Receive ten at the new location.
  let product = await open(page, '/products');
  await product.getByRole('searchbox').fill(sku);
  await product.getByRole('link', { name: sku }).click();
  product = front(page);
  await expect(product.getByRole('heading', { name: `E2E tee ${tag}` })).toBeVisible();
  await stockRow(product, location).getByRole('button', { name: /Adjust/ }).click();
  const adjust = page.getByRole('dialog', { name: 'Adjust stock' });
  await adjust.getByLabel('Change').fill('10');
  await adjust.getByLabel('Reason').selectOption('received');
  await adjust.getByRole('button', { name: 'Save adjustment' }).click();
  await expect(adjust).toBeHidden();
  await expectStock(product, location, { onHand: '10', reserved: '0', available: '10' });

  // An order for four, shipped from the new location.
  let order = await open(page, '/orders/new');
  await order.getByLabel(/^Ship from/).selectOption(location);
  await order.getByLabel(/^Customer name/).fill(`E2E customer ${tag}`);
  const shipping = order.getByRole('group', { name: 'Shipping address' });
  await shipping.getByLabel(/^Address \(required\)/).fill('Storgatan 1');
  await shipping.getByLabel(/^Postal code/).fill('111 22');
  await shipping.getByLabel(/^City/).fill('Stockholm');
  const line = order.getByRole('listitem', { name: 'Line 1' });
  await line.getByLabel(/^SKU/).fill(sku);
  await line.getByLabel(/^Quantity/).fill('4');
  await line.getByLabel(/^Unit price/).fill('199.50');
  await order.getByRole('button', { name: 'Create order' }).click();
  order = front(page);
  await expect(order.getByText('Ships from ' + location, { exact: false })).toBeVisible();

  // Confirming reserves the four.
  await order.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(order.getByText('Confirmed', { exact: true }).first()).toBeVisible();

  // First parcel: one unit, with a tracking number.
  await order.getByRole('button', { name: 'Ship', exact: true }).click();
  let ship = page.getByRole('dialog', { name: /^Ship order/ });
  await ship.getByLabel(`Units of ${sku} to ship now`).fill('1');
  await ship.getByLabel('Carrier').fill('PostNord');
  await ship.getByLabel('Tracking number').fill(`0037${tag}`);
  await ship.getByRole('button', { name: 'Ship 1 units' }).click();
  await expect(ship).toBeHidden();
  const shipments = order.getByRole('region', { name: 'Shipments' });
  await expect(shipments.getByText(`0037${tag}`)).toBeVisible();

  // Second parcel: the three left, which ships the order.
  await order.getByRole('button', { name: 'Ship', exact: true }).click();
  ship = page.getByRole('dialog', { name: /^Ship order/ });
  await expect(ship.getByLabel(`Units of ${sku} to ship now`)).toHaveValue('3');
  await ship.getByRole('button', { name: 'Ship 3 units' }).click();
  await expect(ship).toBeHidden();
  await expect(order.getByRole('button', { name: 'Ship', exact: true })).toBeHidden();

  // The second parcel was recorded by mistake: void it, which reopens the order.
  const parcel2 = shipments.getByRole('listitem').first();
  await parcel2.getByRole('button', { name: 'Void shipment' }).click();
  await parcel2.getByLabel('Reason (optional)').fill('E2E: recorded by mistake');
  await parcel2.getByRole('group', { name: 'Void shipment' }).getByRole('button', { name: 'Void shipment' }).click();
  await expect(parcel2.getByText('Voided', { exact: true })).toBeVisible();
  await expect(order.getByRole('button', { name: 'Ship', exact: true })).toBeVisible();

  // On the product page: one unit left, three back on the shelf and still reserved.
  product = await open(page, '/products');
  await product.getByRole('searchbox').fill(sku);
  await product.getByRole('link', { name: sku }).click();
  product = front(page);
  await expectStock(product, location, { onHand: '9', reserved: '3', available: '6' });
});

function stockRow(pane, location) {
  return pane.getByRole('region', { name: 'Stock per location' }).getByRole('row').filter({ hasText: location });
}

async function expectStock(pane, location, { onHand, reserved, available }) {
  const cells = stockRow(pane, location).getByRole('gridcell');
  await expect(cells.nth(2)).toHaveText(onHand);
  await expect(cells.nth(3)).toHaveText(reserved);
  await expect(cells.nth(4)).toHaveText(available);
}
