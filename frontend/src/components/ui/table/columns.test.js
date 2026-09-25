import { formatList, parseList, withFilterDefaults } from './columns.js';

describe('multi-value filters', () => {
  const options = [
    { value: 'pending', label: 'Pending' },
    { value: 'shipped', label: 'Shipped' },
    { value: 'on_hold', label: 'On hold' },
  ];

  it('reads a comma-joined value, including a single value from an older link', () => {
    expect(parseList('pending,shipped')).toEqual(['pending', 'shipped']);
    expect(parseList('on_hold')).toEqual(['on_hold']);
    expect(parseList(' a, ,b ')).toEqual(['a', 'b']);
    expect(parseList(undefined)).toEqual([]);
  });

  it('writes the choices in the order of the options, so a choice has one URL', () => {
    expect(formatList(['on_hold', 'pending'], options)).toBe('pending,on_hold');
    expect(formatList(['gone', 'shipped'], options)).toBe('shipped,gone');
    expect(formatList([], options)).toBeUndefined();
  });

  it('matches a row whose value, or any item of it, is chosen', () => {
    const { filterFn } = withFilterDefaults({ id: 'tags', meta: { filter: { type: 'multi', options } } });
    const row = (value) => ({ getValue: () => value });

    expect(filterFn(row('pending'), 'status', 'pending,shipped')).toBe(true);
    expect(filterFn(row('on_hold'), 'status', 'pending,shipped')).toBe(false);
    expect(filterFn(row(['VIP', 'gift']), 'tags', 'gift')).toBe(true);
    expect(filterFn(row([]), 'tags', 'gift')).toBe(false);
  });

  it('leaves other filters as they were', () => {
    expect(withFilterDefaults({ id: 'status', meta: { filter: { options } } }).filterFn).toBe('equalsString');
  });
});
