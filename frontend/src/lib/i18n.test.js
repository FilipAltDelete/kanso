import { messages, translate } from './i18n.jsx';

describe('i18n', () => {
  it('has the same keys in every language', () => {
    expect(Object.keys(messages.sv).sort()).toEqual(Object.keys(messages.en).sort());
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
