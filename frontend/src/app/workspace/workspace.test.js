import { activeTab, canClose, emptyWorkspace, layoutBoxes, paneIds, restoreWorkspace, workspaceReducer } from './workspace.js';

function run(state, ...actions) {
  return actions.reduce(workspaceReducer, state);
}

const opened = run(
  emptyWorkspace(),
  { type: 'open', id: 'home', href: '/' },
  { type: 'open', id: 'orders', href: '/orders?f.status=packed' },
  { type: 'open', id: 'products', href: '/products' },
);

const tabsOf = (state) => paneIds(state.layout).map((id) => state.panes[id].tabs);

function splitOff(state, tab, pane, side, id = side) {
  return workspaceReducer(state, { type: 'move', tab, pane, side, newPane: id, newSplit: `split-${id}` });
}

describe('opening pages', () => {
  it('opens each new page as a tab next to the active one and shows it', () => {
    expect(opened.panes.main.tabs).toEqual(['home', 'orders', 'products']);
    expect(activeTab(opened).id).toBe('products');
  });

  it('brings an open page forward instead of opening it twice, whatever it is filtered on', () => {
    const state = workspaceReducer(opened, { type: 'open', id: 'again', href: '/orders' });
    expect(Object.keys(state.tabs)).toHaveLength(3);
    expect(activeTab(state).id).toBe('orders');
  });

  it('opens a shared address with its own filters rather than reusing a tab with others', () => {
    const state = workspaceReducer(opened, { type: 'open', id: 'link', href: '/orders?f.status=pending', match: 'href' });
    expect(activeTab(state).href).toBe('/orders?f.status=pending');
    expect(Object.keys(state.tabs)).toHaveLength(4);
  });

  it('opens a second tab of the same page when asked, without leaving the current one for a background tab', () => {
    const state = workspaceReducer(opened, { type: 'open', id: 'bg', href: '/products', newTab: true, background: true });
    expect(state.panes.main.tabs).toEqual(['home', 'orders', 'products', 'bg']);
    expect(activeTab(state).id).toBe('products');
  });

  it('keeps each tab on the address its own page last navigated to', () => {
    const state = workspaceReducer(opened, { type: 'navigate', tab: 'orders', href: '/orders/o1' });
    expect(state.tabs.orders.href).toBe('/orders/o1');
  });
});

describe('closing tabs', () => {
  it('shows the tab to the right of the one closed, or the left one at the end', () => {
    const middle = run(opened, { type: 'activate', pane: 'main', tab: 'orders' }, { type: 'close', tab: 'orders' });
    expect(activeTab(middle).id).toBe('products');

    const last = workspaceReducer(opened, { type: 'close', tab: 'products' });
    expect(activeTab(last).id).toBe('orders');
  });

  it('opens the dashboard when the last tab closes, so the workspace is never empty', () => {
    const state = run(opened, { type: 'close', tab: 'home' }, { type: 'close', tab: 'orders' }, { type: 'close', tab: 'products' });
    expect(Object.keys(state.panes)).toEqual(['main']);
    expect(activeTab(state)).toEqual({ id: 'home-products', href: '/' });

    // The dashboard alone cannot be closed: it would only open again.
    expect(canClose(state, 'home-products')).toBe(false);
    expect(workspaceReducer(state, { type: 'close', tab: 'home-products' })).toBe(state);
    expect(canClose(opened, 'home')).toBe(true);
  });

  it('opens the dashboard in the pane left when the last tab of a split closes', () => {
    const split = splitOff(run(emptyWorkspace(), { type: 'open', id: 'a', href: '/orders' }, { type: 'open', id: 'b', href: '/products' }), 'b', 'main', 'right');
    const state = run(split, { type: 'close', tab: 'a' }, { type: 'close', tab: 'b' });
    // Closing a's pane left b's; closing b left that one pane, with the dashboard in it.
    expect(state.layout).toEqual({ type: 'pane', id: 'right' });
    expect(state.panes.right.tabs).toEqual(['home-b']);
  });
});

describe('splitting the screen', () => {
  const right = splitOff(opened, 'orders', 'main', 'right');

  it('moves a tab dropped on a side into a new pane on that side, and works there', () => {
    expect(right.layout).toEqual({
      type: 'split',
      id: 'split-right',
      direction: 'row',
      ratio: 0.5,
      a: { type: 'pane', id: 'main' },
      b: { type: 'pane', id: 'right' },
    });
    expect(tabsOf(right)).toEqual([['home', 'products'], ['orders']]);
    expect(right.focused).toBe('right');
  });

  it('puts the new pane first for the left and top sides', () => {
    expect(paneIds(splitOff(opened, 'orders', 'main', 'left').layout)).toEqual(['left', 'main']);

    const top = splitOff(opened, 'orders', 'main', 'top');
    expect(top.layout.direction).toBe('column');
    expect(paneIds(top.layout)).toEqual(['top', 'main']);
  });

  it('splits a pane that is already half of a split, in the other direction', () => {
    const grid = splitOff(right, 'products', 'main', 'bottom');
    expect(tabsOf(grid)).toEqual([['home'], ['products'], ['orders']]);
    expect(grid.layout.a).toMatchObject({ type: 'split', direction: 'column' });
    expect(grid.layout.b).toEqual({ type: 'pane', id: 'right' });
  });

  it('moves the only tab of one pane beside another, closing the pane it left', () => {
    const state = splitOff(right, 'orders', 'main', 'bottom');
    expect(tabsOf(state)).toEqual([['home', 'products'], ['orders']]);
    expect(state.layout.direction).toBe('column');
    expect(state.panes.right).toBeUndefined();
  });

  it('refuses to split a pane off its own only tab', () => {
    const one = run(emptyWorkspace(), { type: 'open', id: 'home', href: '/' });
    expect(splitOff(one, 'home', 'main', 'right')).toBe(one);
  });

  it('refuses a fifth pane', () => {
    const many = run(opened, { type: 'open', id: 'customers', href: '/customers' }, { type: 'open', id: 'locations', href: '/locations' });
    const four = [
      ['orders', 'main', 'right'],
      ['products', 'main', 'bottom'],
      ['customers', 'main', 'left'],
    ].reduce((state, [tab, pane, side]) => splitOff(state, tab, pane, side), many);

    expect(paneIds(four.layout)).toHaveLength(4);
    expect(splitOff(four, 'locations', 'main', 'top')).toBe(four);
  });

  it('opens menu pages in the pane last used', () => {
    const state = workspaceReducer(right, { type: 'open', id: 'customers', href: '/customers' });
    expect(state.panes.right.tabs).toEqual(['orders', 'customers']);
  });

  it('closes a pane its last tab was dragged out of, and gives its space back', () => {
    const state = workspaceReducer(right, { type: 'move', tab: 'orders', pane: 'main', index: 0 });
    expect(state.layout).toEqual({ type: 'pane', id: 'main' });
    expect(state.panes.main).toEqual({ id: 'main', tabs: ['orders', 'home', 'products'], active: 'orders' });
    expect(state.focused).toBe('main');
  });

  it('closes a pane when its last tab is closed, and works in the other', () => {
    const state = workspaceReducer(right, { type: 'close', tab: 'orders' });
    expect(state.layout).toEqual({ type: 'pane', id: 'main' });
    expect(state.focused).toBe('main');
  });

  it('keeps a split between 15% and 85%', () => {
    expect(workspaceReducer(right, { type: 'resize', split: 'split-right', ratio: 0.99 }).layout.ratio).toBe(0.85);
    expect(workspaceReducer(right, { type: 'resize', split: 'split-right', ratio: 0 }).layout.ratio).toBe(0.15);
  });
});

describe('dropping a page from the menu', () => {
  const place = (state, action) =>
    workspaceReducer(state, { type: 'place', id: 'dropped', href: '/customers', newPane: 'new', newSplit: 'split-new', ...action });

  it('opens it as a new tab in the pane it was dropped on, even if it is open elsewhere', () => {
    const state = place(workspaceReducer(opened, { type: 'open', id: 'customers', href: '/customers' }), { pane: 'main', index: 1 });
    expect(state.panes.main.tabs).toEqual(['home', 'dropped', 'orders', 'products', 'customers']);
    expect(activeTab(state).id).toBe('dropped');
  });

  it('opens it in a new pane beside the one dropped on its edge', () => {
    const state = place(opened, { pane: 'main', side: 'right' });
    expect(tabsOf(state)).toEqual([['home', 'orders', 'products'], ['dropped']]);
    expect(state.focused).toBe('new');
  });

  it('opens it beside an empty pane by filling that pane instead', () => {
    const state = place(emptyWorkspace(), { pane: 'main', side: 'right' });
    expect(state.layout).toEqual({ type: 'pane', id: 'main' });
    expect(state.panes.main.tabs).toEqual(['dropped']);
  });
});

describe('placing the panes', () => {
  it('gives each pane its share of the screen and each split its divider', () => {
    const grid = workspaceReducer(splitOff(splitOff(opened, 'orders', 'main', 'right'), 'products', 'main', 'bottom'), {
      type: 'resize',
      split: 'split-right',
      ratio: 0.25,
    });
    const boxes = layoutBoxes(grid.layout);

    expect(boxes.panes).toEqual({
      main: { x: 0, y: 0, w: 0.25, h: 0.5 },
      bottom: { x: 0, y: 0.5, w: 0.25, h: 0.5 },
      right: { x: 0.25, y: 0, w: 0.75, h: 1 },
    });
    expect(boxes.splits.map((split) => [split.id, split.direction, split.box])).toEqual([
      ['split-right', 'row', { x: 0, y: 0, w: 1, h: 1 }],
      ['split-bottom', 'column', { x: 0, y: 0, w: 0.25, h: 1 }],
    ]);
  });
});

describe('reordering tabs', () => {
  it('moves a tab to where it was dropped, either way', () => {
    const right = workspaceReducer(opened, { type: 'move', tab: 'home', pane: 'main', index: 3 });
    expect(right.panes.main.tabs).toEqual(['orders', 'products', 'home']);

    const left = workspaceReducer(opened, { type: 'move', tab: 'products', pane: 'main', index: 0 });
    expect(left.panes.main.tabs).toEqual(['products', 'home', 'orders']);
  });
});

describe('restoring a stored workspace', () => {
  it('round-trips, split tree and all', () => {
    const grid = splitOff(splitOff(opened, 'orders', 'main', 'right'), 'products', 'main', 'bottom');
    expect(restoreWorkspace(JSON.parse(JSON.stringify(grid)))).toEqual(grid);
  });

  it('refuses what is not a workspace', () => {
    expect(restoreWorkspace(null)).toBeNull();
    expect(restoreWorkspace({ panes: 'x', tabs: {} })).toBeNull();
    expect(restoreWorkspace({ panes: [], tabs: {} })).toBeNull();
  });

  it('drops tabs with no address and the panes that leaves empty, closing up the layout', () => {
    const restored = restoreWorkspace({
      tabs: { a: { href: '/' }, b: { href: 'https://elsewhere' } },
      panes: {
        one: { id: 'one', tabs: ['a', 'missing'], active: 'missing' },
        two: { id: 'two', tabs: ['b'], active: 'b' },
      },
      layout: { type: 'split', id: 's', direction: 'row', ratio: 7, a: { type: 'pane', id: 'one' }, b: { type: 'pane', id: 'two' } },
      focused: 'two',
    });

    expect(restored).toEqual({
      tabs: { a: { id: 'a', href: '/' } },
      panes: { one: { id: 'one', tabs: ['a'], active: 'a' } },
      layout: { type: 'pane', id: 'one' },
      focused: 'one',
    });
  });
});
