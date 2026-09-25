import { stockPerLocation } from './stockRows.js';

const locations = [
  { id: 'a', code: 'WH1', name: 'Main' },
  { id: 'b', code: 'ST1', name: 'Store' },
];

describe('stock per location', () => {
  it('shows every location, with zeros and version 0 where there is no stock yet', () => {
    const rows = stockPerLocation(locations, [{ locationId: 'b', onHand: 7, reserved: 2, available: 5, version: 3, updatedAt: '2026-09-25T10:00:00+00:00' }]);

    expect(rows.map((row) => [row.location.code, row.onHand, row.reserved, row.available, row.version])).toEqual([
      ['WH1', 0, 0, 0, 0],
      ['ST1', 7, 2, 5, 3],
    ]);
  });

  it('ignores a level for a location it was not given', () => {
    expect(stockPerLocation(locations, [{ locationId: 'gone', onHand: 1, reserved: 0, available: 1, version: 1 }])).toHaveLength(2);
  });
});
