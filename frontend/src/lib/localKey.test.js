import { localKey } from './localKey.js';

describe('local keys', () => {
  it('never repeats on a page, whatever the prefix', () => {
    const keys = Array.from({ length: 50 }, (_, index) => localKey(index % 2 ? 'line' : 'row'));

    expect(new Set(keys).size).toBe(50);
  });

  it('needs no secure context', () => {
    const original = globalThis.crypto;
    Object.defineProperty(globalThis, 'crypto', { value: {}, configurable: true });
    try {
      expect(localKey('line')).toMatch(/^line-\d+$/);
    } finally {
      Object.defineProperty(globalThis, 'crypto', { value: original, configurable: true });
    }
  });
});
