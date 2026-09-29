import { essentials, translate } from './i18n.jsx';
import en from './messages/en.js';
import sv from './messages/sv.js';

const messages = { en, sv };

describe('i18n', () => {
  it('has the same keys in every language', () => {
    expect(Object.keys(messages.sv).sort()).toEqual(Object.keys(messages.en).sort());
    expect(Object.keys(essentials.sv).sort()).toEqual(Object.keys(essentials.en).sort());
  });

  it('keeps each key in one place: the catalogs or the essentials, not both', () => {
    expect(Object.keys(essentials.en).filter((key) => key in messages.en)).toEqual([]);
  });

  it('falls back to English, then to the key', () => {
    expect(translate('sv', 'auth.signIn')).toBe('Logga in');
    expect(translate('de', 'auth.signIn')).toBe('Sign in');
    expect(translate('sv', 'no.such.key')).toBe('no.such.key');
  });

  it('fills placeholders and leaves unknown ones visible', () => {
    expect(translate('sv', 'table.range', { from: 1, to: 25, total: '1 000' })).toBe('1–25 av 1 000');
    expect(translate('en', 'table.selected', {})).toBe('{count} selected');
  });
});
