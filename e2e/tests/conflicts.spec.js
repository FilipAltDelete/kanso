import { expect, signIn, test } from './support/fixtures.js';
import { createProduct, front, open, uniqueTag } from './support/app.js';

/**
 * Two operators edit the same product. The second to save is not allowed to
 * overwrite the first: the dialog says so and shows the current values.
 */
test('a stale product edit is refused and reloaded, not saved over', async ({ page, browser }) => {
  const tag = uniqueTag();
  const sku = `E2E-EDIT-${tag}`;
  await createProduct(page, sku, `E2E original ${tag}`);

  // A second operator: another browser, signed in on its own.
  const other = await browser.newContext({ locale: 'en-GB', baseURL: test.info().project.use.baseURL });
  await signIn(other);
  const second = await other.newPage();

  const openEditor = async (p) => {
    const pane = await open(p, '/products');
    await pane.getByRole('searchbox').fill(sku);
    await pane.getByRole('link', { name: sku }).click();
    await front(p).getByRole('button', { name: 'Edit', exact: true }).click();

    return p.getByRole('dialog', { name: 'Edit product' });
  };

  const mine = await openEditor(page);
  const theirs = await openEditor(second);

  await theirs.getByLabel(/^Name/).fill(`E2E theirs ${tag}`);
  await theirs.getByRole('button', { name: 'Save' }).click();
  await expect(theirs).toBeHidden();

  await mine.getByLabel(/^Name/).fill(`E2E mine ${tag}`);
  await mine.getByRole('button', { name: 'Save' }).click();

  await expect(mine.getByText('Someone else changed this product while you were editing.', { exact: false })).toBeVisible();
  await expect(mine.getByLabel(/^Name/)).toHaveValue(`E2E theirs ${tag}`);
  await other.close();
});
