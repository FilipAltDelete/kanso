/**
 * Money on the wire is integer minor units plus an ISO 4217 code (CLAUDE.md).
 * These helpers never go through a float: formatting hands Intl a decimal
 * string, and parsing builds the integer from the typed digits.
 */

/** How many minor-unit digits a currency has: 2 for SEK and EUR, 0 for JPY, 3 for KWD. */
export function minorDigits(currency) {
  return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits;
}

/** 19950 → "199.50", -5 → "-0.05": the exact decimal, as a string. */
export function toDecimalString(minor, currency) {
  const digits = minorDigits(currency);
  const negative = minor < 0;
  const text = String(Math.abs(minor)).padStart(digits + 1, '0');
  const whole = text.slice(0, text.length - digits);
  const fraction = digits > 0 ? `.${text.slice(text.length - digits)}` : '';

  return `${negative ? '-' : ''}${whole}${fraction}`;
}

export function formatMoney(minor, currency, locale) {
  // Intl formats a decimal string exactly; a Number could round.
  return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(toDecimalString(minor, currency));
}

/**
 * What an operator typed ("199,50", "1 299.5", "1.299,50" in Swedish) to
 * minor units, or null when it is not an amount with at most the currency's
 * number of decimals. The decimal separator is the locale's; the other of
 * "," and "." and spaces are read as grouping.
 */
export function parseMoney(input, currency, locale) {
  const decimal = new Intl.NumberFormat(locale).formatToParts(1.5).find((part) => part.type === 'decimal')?.value ?? '.';
  const group = decimal === ',' ? '.' : ',';
  const text = String(input).trim().replace(/[\s\u00a0\u202f]/g, '').split(group).join('');
  const match = new RegExp(`^(-?)(\\d+)(?:\\${decimal}(\\d*))?$`).exec(text);
  if (!match) return null;

  const [, sign, whole, fraction = ''] = match;
  const digits = minorDigits(currency);
  if (fraction.length > digits) return null;

  const minor = Number(`${whole}${fraction.padEnd(digits, '0')}`);
  if (!Number.isSafeInteger(minor)) return null;

  return sign === '-' ? -minor : minor;
}
