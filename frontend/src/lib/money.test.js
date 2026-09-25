import { formatMoney, minorDigits, parseMoney, toDecimalString } from './money.js';

// Intl uses narrow no-break spaces in some locales; compare with plain ones.
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

describe('money', () => {
  it('knows each currency’s minor units', () => {
    expect(minorDigits('SEK')).toBe(2);
    expect(minorDigits('JPY')).toBe(0);
    expect(minorDigits('KWD')).toBe(3);
  });

  it('writes minor units as an exact decimal', () => {
    expect(toDecimalString(19950, 'SEK')).toBe('199.50');
    expect(toDecimalString(5, 'SEK')).toBe('0.05');
    expect(toDecimalString(-5, 'EUR')).toBe('-0.05');
    expect(toDecimalString(1500, 'JPY')).toBe('1500');
    expect(toDecimalString(1, 'KWD')).toBe('0.001');
  });

  it('formats for the locale without going through a float', () => {
    expect(plain(formatMoney(19950, 'SEK', 'sv'))).toBe('199,50 kr');
    expect(formatMoney(19950, 'EUR', 'en')).toBe('€199.50');
    // The largest amount JSON gives JavaScript exactly, to the last minor unit.
    expect(formatMoney(Number.MAX_SAFE_INTEGER, 'USD', 'en')).toBe('$90,071,992,547,409.91');
    expect(plain(formatMoney(-5, 'SEK', 'sv'))).toBe('−0,05 kr');
  });

  it('reads what an operator types in their locale', () => {
    expect(parseMoney('199,50', 'SEK', 'sv')).toBe(19950);
    expect(parseMoney('1 299,5', 'SEK', 'sv')).toBe(129950);
    expect(parseMoney('1.299,50', 'SEK', 'sv')).toBe(129950);
    expect(parseMoney('199.50', 'EUR', 'en')).toBe(19950);
    expect(parseMoney('1,299', 'EUR', 'en')).toBe(129900);
    expect(parseMoney('0.1', 'EUR', 'en')).toBe(10);
    expect(parseMoney('12', 'JPY', 'en')).toBe(12);
    expect(parseMoney(' 7 ', 'SEK', 'sv')).toBe(700);
  });

  it('refuses what is not an amount in the currency', () => {
    expect(parseMoney('', 'SEK', 'sv')).toBeNull();
    expect(parseMoney('abc', 'SEK', 'sv')).toBeNull();
    expect(parseMoney('1,999', 'SEK', 'sv')).toBeNull();
    expect(parseMoney('1.5', 'JPY', 'en')).toBeNull();
    expect(parseMoney('1e3', 'EUR', 'en')).toBeNull();
    expect(parseMoney('99999999999999999999', 'EUR', 'en')).toBeNull();
  });

  it('round-trips', () => {
    for (const minor of [0, 1, 99, 100, 19950, 123456789]) {
      expect(parseMoney(toDecimalString(minor, 'EUR'), 'EUR', 'en')).toBe(minor);
    }
  });
});
