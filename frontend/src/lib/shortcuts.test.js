import { messages } from './i18n.jsx';
import { createMatcher, isShortcutEvent, loadShortcutsEnabled, saveShortcutsEnabled, SHORTCUT_GROUPS, SHORTCUTS } from './shortcuts.js';

const active = (...ids) => SHORTCUTS.filter((shortcut) => ids.includes(shortcut.id));

function clock() {
  let time = 0;
  return { now: () => time, advance: (ms) => (time += ms) };
}

describe('the shortcut registry', () => {
  it('has a label in both languages and a help section for every shortcut', () => {
    for (const shortcut of SHORTCUTS) {
      expect(messages.en[shortcut.labelKey], shortcut.id).toBeTruthy();
      expect(messages.sv[shortcut.labelKey], shortcut.id).toBeTruthy();
      expect(SHORTCUT_GROUPS).toContain(shortcut.group);
    }
    for (const group of SHORTCUT_GROUPS) expect(messages.sv[`shortcuts.group.${group}`]).toBeTruthy();
  });

  it('gives no two shortcuts that can be on at once the same keys, nor one the start of another', () => {
    for (const group of SHORTCUT_GROUPS.filter((name) => name !== 'global')) {
      const together = SHORTCUTS.filter((shortcut) => !shortcut.builtIn && (shortcut.group === 'global' || shortcut.group === group)).map((shortcut) => shortcut.keys.join(' '));
      for (const keys of together) {
        expect(together.filter((other) => other === keys || other.startsWith(`${keys} `))).toEqual([keys]);
      }
    }
  });

  it('uses no modifier keys for the ones it matches', () => {
    for (const shortcut of SHORTCUTS.filter((item) => !item.builtIn)) {
      expect(shortcut.keys.every((key) => key.length === 1)).toBe(true);
    }
  });
});

describe('matching keys', () => {
  it('fires a single key at once', () => {
    const matcher = createMatcher();
    expect(matcher.feed('n', active('orders.new'))?.id).toBe('orders.new');
    expect(matcher.feed('x', active('orders.new'))).toBeNull();
  });

  it('follows a sequence like "g o"', () => {
    const matcher = createMatcher();
    const shortcuts = active('goOrders', 'goProducts', 'orders.new');

    expect(matcher.feed('g', shortcuts)).toBeNull();
    expect(matcher.pending()).toEqual(['g']);
    expect(matcher.feed('o', shortcuts)?.id).toBe('goOrders');
    expect(matcher.pending()).toEqual([]);
    expect(matcher.feed('g', shortcuts)).toBeNull();
    expect(matcher.feed('p', shortcuts)?.id).toBe('goProducts');
  });

  it('starts over when a sequence goes nowhere, and takes the key on its own', () => {
    const matcher = createMatcher();
    const shortcuts = active('goOrders', 'orders.new');

    matcher.feed('g', shortcuts);
    expect(matcher.feed('n', shortcuts)?.id).toBe('orders.new');
    matcher.feed('g', shortcuts);
    expect(matcher.feed('z', shortcuts)).toBeNull();
    expect(matcher.feed('o', shortcuts)).toBeNull();
  });

  it('forgets a sequence that was not finished in time', () => {
    const time = clock();
    const matcher = createMatcher({ now: time.now, timeout: 1000 });
    const shortcuts = active('goOrders');

    matcher.feed('g', shortcuts);
    time.advance(1001);
    expect(matcher.feed('o', shortcuts)).toBeNull();
  });

  it('matches only shortcuts that have a handler', () => {
    const matcher = createMatcher();
    expect(matcher.feed('t', active('orders.new'))).toBeNull();
    expect(matcher.feed('t', active('order.tag'))?.id).toBe('order.tag');
  });
});

describe('which key presses are for shortcuts', () => {
  const doc = { querySelector: () => null };
  const press = (key, overrides = {}) => ({ key, target: document.body, ctrlKey: false, metaKey: false, altKey: false, repeat: false, isComposing: false, defaultPrevented: false, ...overrides });

  it('takes plain keys, with Shift for "?"', () => {
    expect(isShortcutEvent(press('g'), doc)).toBe(true);
    expect(isShortcutEvent(press('?', { shiftKey: true }), doc)).toBe(true);
  });

  it('leaves typing in fields alone', () => {
    for (const html of ['<input>', '<input type="search">', '<textarea></textarea>', '<select></select>', '<div contenteditable="true"></div>']) {
      const holder = document.createElement('div');
      holder.innerHTML = html;
      expect(isShortcutEvent(press('n', { target: holder.firstChild }), doc)).toBe(false);
    }
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    expect(isShortcutEvent(press('t', { target: checkbox }), doc)).toBe(true);
  });

  it('leaves browser and screen reader keys alone', () => {
    expect(isShortcutEvent(press('p', { ctrlKey: true }), doc)).toBe(false);
    expect(isShortcutEvent(press('o', { metaKey: true }), doc)).toBe(false);
    expect(isShortcutEvent(press('n', { altKey: true }), doc)).toBe(false);
    expect(isShortcutEvent(press('Tab'), doc)).toBe(false);
    expect(isShortcutEvent(press('g', { repeat: true }), doc)).toBe(false);
    expect(isShortcutEvent(press('g', { isComposing: true }), doc)).toBe(false);
    expect(isShortcutEvent(press('/', { defaultPrevented: true }), doc)).toBe(false);
  });

  it('does nothing behind a modal dialog', () => {
    expect(isShortcutEvent(press('g'), { querySelector: () => ({}) })).toBe(false);
  });
});

describe('switching shortcuts off', () => {
  it('remembers the choice in the browser, and survives storage that refuses', () => {
    const store = new Map();
    const storage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value), removeItem: (key) => store.delete(key) };

    expect(loadShortcutsEnabled(storage)).toBe(true);
    saveShortcutsEnabled(false, storage);
    expect(loadShortcutsEnabled(storage)).toBe(false);
    saveShortcutsEnabled(true, storage);
    expect(loadShortcutsEnabled(storage)).toBe(true);

    const broken = {
      getItem: () => {
        throw new Error('denied');
      },
      setItem: () => {
        throw new Error('denied');
      },
    };
    expect(loadShortcutsEnabled(broken)).toBe(true);
    expect(() => saveShortcutsEnabled(false, broken)).not.toThrow();
  });
});
