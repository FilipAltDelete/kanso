import { EMPTY_VIEW, isDefaultView, mergeSearch, parseSort, parseView, serializeSort, toSearch, viewDefaults } from './viewState.js';

describe('table view in the URL', () => {
  it('reads sort, search, filters, page and size', () => {
    const view = parseView({ sort: '-createdAt,number', q: 'anna', 'f.status': 'pending', page: 3, size: 100 });

    expect(view).toEqual({
      sorting: [
        { id: 'createdAt', desc: true },
        { id: 'number', desc: false },
      ],
      globalFilter: 'anna',
      columnFilters: [{ id: 'status', value: 'pending' }],
      pageIndex: 2,
      pageSize: 100,
    });
  });

  it('falls back to the defaults for missing or invalid values', () => {
    const defaults = { sorting: [{ id: 'createdAt', desc: true }] };

    expect(parseView({ page: 'x', size: 7, q: 123 }, { defaults })).toEqual({
      ...EMPTY_VIEW,
      sorting: [{ id: 'createdAt', desc: true }],
      globalFilter: '123',
    });
  });

  it('leaves defaults out of the URL and round-trips the rest', () => {
    const defaults = { sorting: [{ id: 'createdAt', desc: true }] };
    expect(toSearch(viewDefaults(defaults), { defaults })).toEqual({ sort: undefined, q: undefined, page: undefined, size: undefined });

    const view = { sorting: [{ id: 'total', desc: false }], globalFilter: 'x', columnFilters: [{ id: 'channel', value: 'Webshop' }], pageIndex: 1, pageSize: 50 };
    expect(parseView(mergeSearch({}, view, { defaults }), { defaults })).toEqual(view);
  });

  it('writes "none" when a list with a default sort is left unsorted', () => {
    const defaults = { sorting: [{ id: 'createdAt', desc: true }] };
    const search = mergeSearch({}, { ...viewDefaults(defaults), sorting: [] }, { defaults });

    expect(search).toEqual({ sort: 'none' });
    expect(parseView(search, { defaults }).sorting).toEqual([]);
  });

  it('keeps other parameters and replaces only its own', () => {
    const previous = { tab: 'lines', sort: 'number', 'f.status': 'pending', 'lines.sort': 'sku' };
    const next = mergeSearch(previous, { ...EMPTY_VIEW, columnFilters: [{ id: 'channel', value: 'Amazon' }] });

    expect(next).toEqual({ tab: 'lines', 'lines.sort': 'sku', 'f.channel': 'Amazon' });
  });

  it('keeps two tables apart with a prefix', () => {
    const search = { sort: 'number', 'lines.sort': '-sku', 'lines.f.location': 'A1' };

    expect(parseView(search, { prefix: 'lines.' })).toMatchObject({
      sorting: [{ id: 'sku', desc: true }],
      columnFilters: [{ id: 'location', value: 'A1' }],
    });
  });

  it('serializes sorting compactly', () => {
    expect(serializeSort([{ id: 'a', desc: true }, { id: 'b', desc: false }])).toBe('-a,b');
    expect(parseSort('')).toEqual([]);
  });

  it('knows when a view has been narrowed or reordered', () => {
    const base = viewDefaults({ sorting: [{ id: 'createdAt', desc: true }] });

    expect(isDefaultView({ ...base, pageIndex: 4, pageSize: 100 }, base)).toBe(true);
    expect(isDefaultView({ ...base, globalFilter: 'x' }, base)).toBe(false);
    expect(isDefaultView({ ...base, sorting: [] }, base)).toBe(false);
  });

  it('keeps a multi-value filter as one comma-joined parameter', () => {
    const view = parseView({ 'f.tags': 'VIP,gift wrap', 'f.status': 'on_hold' });

    expect(view.columnFilters).toEqual([
      { id: 'tags', value: 'VIP,gift wrap' },
      { id: 'status', value: 'on_hold' },
    ]);
    expect(toSearch(view)).toMatchObject({ 'f.tags': 'VIP,gift wrap', 'f.status': 'on_hold' });
  });
});
