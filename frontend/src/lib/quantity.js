/** Stock is whole units; weight is whole grams (CLAUDE.md: no floats for stored amounts). */

export function formatQuantity(value, locale) {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value);
}

/** +5 / −3 / 0, with a real minus sign, for a movement's change. */
export function formatChange(value, locale) {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0, signDisplay: 'exceptZero' }).format(value);
}

/** Grams under a kilogram as grams, heavier as kilograms: 180 g, 1,25 kg. */
export function formatWeight(grams, locale) {
  if (grams === null || grams === undefined) return '';
  if (grams < 1000) return new Intl.NumberFormat(locale, { style: 'unit', unit: 'gram' }).format(grams);

  return new Intl.NumberFormat(locale, { style: 'unit', unit: 'kilogram', maximumFractionDigits: 3 }).format(grams / 1000);
}

/**
 * What an operator typed as a whole number, or null. Accepts a leading minus
 * (or the Unicode minus) and ignores spaces used as thousands separators;
 * anything with a decimal part is not a quantity.
 */
export function parseQuantity(text) {
  const cleaned = String(text).replace(/[\s\u00a0\u202f]/g, '').replace('\u2212', '-');
  if (!/^[+-]?\d+$/.test(cleaned)) return null;
  const value = Number(cleaned);

  return Number.isSafeInteger(value) ? value : null;
}
