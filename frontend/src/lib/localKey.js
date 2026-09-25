let counter = 0;

/**
 * A key for a row that exists only on this page, such as an order line being
 * typed in. Not crypto.randomUUID(): browsers only offer that on HTTPS or
 * localhost, and a warehouse tablet on http://kanso.local would get a crash
 * instead of a form. Unique for the life of the page, which is all a React
 * key needs.
 */
export function localKey(prefix = 'row') {
  counter += 1;

  return `${prefix}-${counter}`;
}
