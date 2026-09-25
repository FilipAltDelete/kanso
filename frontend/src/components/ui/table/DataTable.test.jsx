import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { I18nProvider } from '../../../lib/i18n.jsx';
import { DataTable } from './DataTable.jsx';

const orders = Array.from({ length: 30 }, (_, index) => ({
  id: `o${index + 1}`,
  number: `KO-${String(index + 1).padStart(3, '0')}`,
  customer: ['Åsa', 'Anna', 'Örjan', 'Bo'][index % 4],
  status: index % 3 === 0 ? 'pending' : 'shipped',
}));

const columns = [
  { accessorKey: 'number', header: 'Order' },
  { accessorKey: 'customer', header: 'Customer', sortingFn: 'locale' },
  {
    accessorKey: 'status',
    header: 'Status',
    meta: { filter: { options: [{ value: 'pending', label: 'Pending' }, { value: 'shipped', label: 'Shipped' }] } },
  },
];

function renderTable(props = {}, locale = 'en') {
  return render(
    <I18nProvider locale={locale}>
      <DataTable label="Orders" data={orders} columns={columns} getRowId={(row) => row.id} getRowLabel={(row) => row.number} {...props} />
    </I18nProvider>,
  );
}

const grid = () => screen.getByRole('grid', { name: 'Orders' });
const bodyRows = () => within(grid()).getAllByRole('row').slice(1);

describe('DataTable', () => {
  it('is a labelled grid with column headers and one page of rows', () => {
    renderTable();

    expect(within(grid()).getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Order', 'Customer', 'Status']);
    expect(bodyRows()).toHaveLength(25);
    expect(screen.getByText('1–25 of 30')).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeTruthy();
  });

  it('sorts by a column and says so with aria-sort', () => {
    renderTable();
    const header = screen.getByRole('columnheader', { name: 'Order' });

    fireEvent.click(within(header).getByRole('button'));
    expect(header.getAttribute('aria-sort')).toBe('ascending');
    expect(bodyRows()[0].textContent).toContain('KO-001');

    fireEvent.click(within(header).getByRole('button'));
    expect(header.getAttribute('aria-sort')).toBe('descending');
    expect(bodyRows()[0].textContent).toContain('KO-030');
  });

  it('sorts text in the order of the language', () => {
    renderTable({}, 'sv');
    fireEvent.click(within(screen.getByRole('columnheader', { name: 'Customer' })).getByRole('button'));

    const customers = [...new Set(bodyRows().map((row) => within(row).getAllByRole('gridcell')[1].textContent))];
    expect(customers).toEqual(['Anna', 'Bo', 'Åsa', 'Örjan']);
  });

  it('filters by a column and by the search box', async () => {
    renderTable();

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'pending' } });
    expect(bodyRows()).toHaveLength(10);
    expect(screen.getByText('1–10 of 10')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'KO-01' } });
    await waitFor(() => expect(bodyRows().map((row) => row.textContent.slice(0, 6))).toEqual(['KO-010', 'KO-013', 'KO-016', 'KO-019']));

    fireEvent.click(screen.getByRole('button', { name: 'Reset view' }));
    expect(bodyRows()).toHaveLength(25);
    expect(screen.getByLabelText('Search').value).toBe('');
  });

  it('says so when nothing matches', async () => {
    renderTable();
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'nothing like this' } });

    expect(await screen.findByText('No rows match the search or filters.')).toBeTruthy();
  });

  it('pages and changes the page size', () => {
    renderTable();

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(screen.getByText('26–30 of 30')).toBeTruthy();
    expect(screen.getByText('Page 2 of 2')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Next page' }).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Rows per page'), { target: { value: '50' } });
    expect(bodyRows()).toHaveLength(30);
  });

  it('reports the view it changes to a controlling parent', () => {
    const onViewChange = vi.fn();
    const view = { sorting: [], globalFilter: '', columnFilters: [], pageIndex: 0, pageSize: 25 };
    renderTable({ view, onViewChange });

    fireEvent.click(within(screen.getByRole('columnheader', { name: 'Customer' })).getByRole('button'));

    expect(onViewChange).toHaveBeenCalledWith({ ...view, sorting: [{ id: 'customer', desc: false }] });
  });

  it('selects rows and hands them to a bulk action', () => {
    const onClick = vi.fn();
    renderTable({ bulkActions: [{ id: 'print', label: 'Print', onClick }] });

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select KO-002' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select KO-005' }));

    const bar = screen.getByRole('region', { name: 'Bulk actions' });
    expect(within(bar).getByText('2 selected')).toBeTruthy();
    expect(bodyRows()[1].getAttribute('aria-selected')).toBe('true');

    fireEvent.click(within(bar).getByRole('button', { name: 'Print' }));
    expect(onClick).toHaveBeenCalledWith(expect.objectContaining({ ids: ['o2', 'o5'], rows: [orders[1], orders[4]] }));

    fireEvent.click(within(bar).getByRole('button', { name: 'Clear selection' }));
    expect(screen.queryByRole('region', { name: 'Bulk actions' })).toBeNull();
  });

  it('selects the whole page from the header and clears the selection when the filter changes', () => {
    renderTable({ bulkActions: [{ id: 'print', label: 'Print', onClick: vi.fn() }] });

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all rows on this page' }));
    expect(screen.getByRole('region', { name: 'Bulk actions' }).textContent).toContain('25 selected');

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'shipped' } });
    expect(screen.queryByRole('region', { name: 'Bulk actions' })).toBeNull();
  });

  it('has a single tab stop and moves between cells with the keyboard', () => {
    const onRowActivate = vi.fn();
    renderTable({ onRowActivate, bulkActions: [{ id: 'print', label: 'Print', onClick: vi.fn() }] });

    const tabStops = grid().querySelectorAll('[tabindex="0"]');
    expect(tabStops).toHaveLength(1);
    // The header's first cell holds the select-all checkbox, which takes the focus.
    expect(tabStops[0]).toBe(screen.getByRole('checkbox', { name: 'Select all rows on this page' }));

    tabStops[0].focus();
    fireEvent.keyDown(document.activeElement, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(within(screen.getByRole('columnheader', { name: 'Order' })).getByRole('button'));

    fireEvent.keyDown(document.activeElement, { key: 'ArrowDown' });
    expect(document.activeElement.textContent).toBe('KO-001');
    expect(document.activeElement.getAttribute('role')).toBe('gridcell');

    fireEvent.keyDown(document.activeElement, { key: 'ArrowDown' });
    fireEvent.keyDown(document.activeElement, { key: ' ' });
    expect(screen.getByRole('checkbox', { name: 'Select KO-002' }).checked).toBe(true);

    fireEvent.keyDown(document.activeElement, { key: 'Enter' });
    expect(onRowActivate).toHaveBeenCalledWith(orders[1]);

    fireEvent.keyDown(document.activeElement, { key: 'End', ctrlKey: true });
    // The last cell of the last row on the page: KO-025's status.
    expect(document.activeElement.textContent).toBe('pending');
    expect(document.activeElement.closest('tr').textContent).toContain('KO-025');

    fireEvent.keyDown(document.activeElement, { key: 'a', ctrlKey: true });
    expect(screen.getByRole('region', { name: 'Bulk actions' }).textContent).toContain('25 selected');

    fireEvent.keyDown(document.activeElement, { key: 'Escape' });
    expect(screen.queryByRole('region', { name: 'Bulk actions' })).toBeNull();

    fireEvent.keyDown(document.activeElement, { key: '/' });
    expect(document.activeElement).toBe(screen.getByLabelText('Search'));
    expect(grid().querySelectorAll('[tabindex="0"]')).toHaveLength(1);
  });

  it('describes its keyboard use and speaks Swedish', () => {
    renderTable({}, 'sv');

    expect(screen.getByRole('grid', { name: 'Orders' }).getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getByText('1–25 av 30')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nästa sida' })).toBeTruthy();
    expect(screen.getByLabelText('Sök')).toBeTruthy();
  });

  it('shows the total from the server and leaves the rows alone in manual mode', () => {
    const onViewChange = vi.fn();
    const view = { sorting: [], globalFilter: '', columnFilters: [], pageIndex: 0, pageSize: 25 };
    renderTable({ manual: true, rowCount: 1234, data: orders.slice(0, 25), view, onViewChange });

    expect(screen.getByText('1–25 of 1,234')).toBeTruthy();
    fireEvent.click(within(screen.getByRole('columnheader', { name: 'Order' })).getByRole('button'));
    expect(bodyRows()[0].textContent).toContain('KO-001');
    expect(onViewChange).toHaveBeenCalledWith({ ...view, sorting: [{ id: 'number', desc: false }] });
  });

  it('marks itself busy while loading', () => {
    renderTable({ loading: true, data: [] });

    expect(grid().getAttribute('aria-busy')).toBe('true');
  });
});
