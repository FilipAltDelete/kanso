import { activeHref } from './navState.js';

describe('the entry in front', () => {
  const hrefs = ['/', '/orders', '/orders/new', '/products', '/products/import', '/locations'];

  it('is the most specific entry that matches the path', () => {
    expect(activeHref('/', hrefs)).toBe('/');
    expect(activeHref('/orders', hrefs)).toBe('/orders');
    expect(activeHref('/orders/new', hrefs)).toBe('/orders/new');
    expect(activeHref('/orders/0199-abc', hrefs)).toBe('/orders');
    expect(activeHref('/products/import', hrefs)).toBe('/products/import');
    expect(activeHref('/products/0199-abc', hrefs)).toBe('/products');
  });

  it('is nothing for a page the menu does not list', () => {
    expect(activeHref('/settings', hrefs)).toBeNull();
    expect(activeHref('/ordersx', hrefs)).toBeNull();
  });
});
