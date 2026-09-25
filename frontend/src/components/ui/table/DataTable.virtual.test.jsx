import { render, screen, within } from '@testing-library/react';
import { I18nProvider } from '../../../lib/i18n.jsx';
import { DataTable, VIRTUALIZE_ABOVE } from './DataTable.jsx';

// jsdom lays nothing out, so the virtualizer is replaced by a fixed window of rows 40–59.
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count, enabled }) => ({
    getVirtualItems: () => (enabled ? Array.from({ length: 20 }, (_, i) => ({ index: 40 + i, start: (40 + i) * 41, end: (41 + i) * 41 })) : []),
    getTotalSize: () => count * 41,
    scrollToIndex: vi.fn(),
  }),
}));

const data = Array.from({ length: 250 }, (_, index) => ({ id: String(index), number: `KO-${index}` }));

describe('a long page', () => {
  it('renders only the rows in view and keeps their place in the grid', () => {
    render(
      <I18nProvider locale="en">
        <DataTable
          label="Orders"
          data={data}
          columns={[{ accessorKey: 'number', header: 'Order' }]}
          getRowId={(row) => row.id}
          defaultView={{ sorting: [], globalFilter: '', columnFilters: [], pageIndex: 0, pageSize: 250 }}
        />
      </I18nProvider>,
    );

    const grid = screen.getByRole('grid');
    const rows = within(grid).getAllByRole('row').filter((row) => row.getAttribute('aria-rowindex'));
    expect(VIRTUALIZE_ABOVE).toBeLessThan(250);
    expect(rows).toHaveLength(21);
    expect(grid.getAttribute('aria-rowcount')).toBe('251');
    expect(rows[1].getAttribute('aria-rowindex')).toBe('42');
    expect(rows[1].textContent).toBe('KO-40');
  });
});
