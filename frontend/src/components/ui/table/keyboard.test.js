import { moveCell } from './keyboard.js';

const grid = { rows: 30, cols: 5 };
const key = (name, modifiers = {}) => ({ key: name, ctrlKey: false, metaKey: false, ...modifiers });

describe('grid keyboard navigation', () => {
  it('moves one cell with the arrows and stops at the edges', () => {
    expect(moveCell({ row: 0, col: 0 }, key('ArrowDown'), grid)).toEqual({ row: 1, col: 0 });
    expect(moveCell({ row: 0, col: 0 }, key('ArrowUp'), grid)).toEqual({ row: -1, col: 0 });
    expect(moveCell({ row: -1, col: 0 }, key('ArrowUp'), grid)).toEqual({ row: -1, col: 0 });
    expect(moveCell({ row: 29, col: 4 }, key('ArrowDown'), grid)).toEqual({ row: 29, col: 4 });
    expect(moveCell({ row: 3, col: 4 }, key('ArrowRight'), grid)).toEqual({ row: 3, col: 4 });
    expect(moveCell({ row: 3, col: 0 }, key('ArrowLeft'), grid)).toEqual({ row: 3, col: 0 });
  });

  it('jumps within a row with Home and End, and across the grid with Ctrl', () => {
    expect(moveCell({ row: 3, col: 2 }, key('Home'), grid)).toEqual({ row: 3, col: 0 });
    expect(moveCell({ row: 3, col: 2 }, key('End'), grid)).toEqual({ row: 3, col: 4 });
    expect(moveCell({ row: 3, col: 2 }, key('Home', { ctrlKey: true }), grid)).toEqual({ row: -1, col: 0 });
    expect(moveCell({ row: 3, col: 2 }, key('End', { metaKey: true }), grid)).toEqual({ row: 29, col: 4 });
  });

  it('pages by ten rows', () => {
    expect(moveCell({ row: 3, col: 1 }, key('PageDown'), grid)).toEqual({ row: 13, col: 1 });
    expect(moveCell({ row: 25, col: 1 }, key('PageDown'), grid)).toEqual({ row: 29, col: 1 });
    expect(moveCell({ row: 5, col: 1 }, key('PageUp'), grid)).toEqual({ row: 0, col: 1 });
  });

  it('stays in the header when there are no rows', () => {
    expect(moveCell({ row: -1, col: 0 }, key('ArrowDown'), { rows: 0, cols: 3 })).toEqual({ row: -1, col: 0 });
  });

  it('ignores keys that are not moves', () => {
    expect(moveCell({ row: 0, col: 0 }, key('a'), grid)).toBeNull();
  });
});
