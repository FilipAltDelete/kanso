import { formatChange, formatQuantity, formatWeight, parseQuantity } from './quantity.js';

describe('quantities', () => {
  it('parses whole numbers as typed, and nothing else', () => {
    expect(parseQuantity('12')).toBe(12);
    expect(parseQuantity(' -3 ')).toBe(-3);
    expect(parseQuantity('+4')).toBe(4);
    expect(parseQuantity('−5')).toBe(-5);
    expect(parseQuantity('1 200')).toBe(1200);
    expect(parseQuantity('1.5')).toBeNull();
    expect(parseQuantity('1,5')).toBeNull();
    expect(parseQuantity('')).toBeNull();
    expect(parseQuantity('abc')).toBeNull();
    expect(parseQuantity('99999999999999999999')).toBeNull();
  });

  it('formats for the locale', () => {
    expect(formatQuantity(1200, 'en')).toBe('1,200');
    expect(formatQuantity(1200, 'sv').replace(/\s/g, ' ')).toBe('1 200');
  });

  it('signs a change, and leaves zero unsigned', () => {
    expect(formatChange(5, 'en')).toBe('+5');
    expect(formatChange(-3, 'en')).toBe('-3');
    expect(formatChange(0, 'en')).toBe('0');
  });

  it('shows light things in grams and heavy things in kilograms', () => {
    expect(formatWeight(180, 'en')).toBe('180 g');
    expect(formatWeight(1250, 'en')).toBe('1.25 kg');
    expect(formatWeight(1250, 'sv')).toBe('1,25 kg');
    expect(formatWeight(null, 'en')).toBe('');
  });
});
