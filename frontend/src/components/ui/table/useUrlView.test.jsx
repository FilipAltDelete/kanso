import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createMemoryHistory, createRootRoute, createRoute, createRouter, Outlet, RouterProvider } from '@tanstack/react-router';
import { I18nProvider } from '../../../lib/i18n.jsx';
import { DataTable } from './DataTable.jsx';
import { useUrlView } from './useUrlView.js';

const rows = Array.from({ length: 60 }, (_, index) => ({ id: String(index + 1), number: `KO-${100 + index}`, status: index % 2 ? 'packed' : 'pending' }));
const columns = [
  { accessorKey: 'number', header: 'Order' },
  { accessorKey: 'status', header: 'Status', meta: { filter: { options: [{ value: 'pending', label: 'Pending' }, { value: 'packed', label: 'Packed' }] } } },
];

function Orders() {
  const urlView = useUrlView({ defaults: { sorting: [{ id: 'number', desc: true }] } });

  return <DataTable {...urlView} label="Orders" data={rows} columns={columns} getRowId={(row) => row.id} />;
}

function renderAt(url) {
  const rootRoute = createRootRoute({ component: Outlet });
  const route = createRoute({ getParentRoute: () => rootRoute, path: '/orders', component: Orders });
  const router = createRouter({
    routeTree: rootRoute.addChildren([route]),
    history: createMemoryHistory({ initialEntries: [url] }),
    scrollRestoration: false,
  });
  render(
    <I18nProvider locale="en">
      <RouterProvider router={router} />
    </I18nProvider>,
  );

  return router;
}

const bodyRows = () => within(screen.getByRole('grid')).getAllByRole('row').slice(1);

describe('a table view kept in the URL', () => {
  it('opens the view the URL describes', async () => {
    renderAt('/orders?sort=number&f.status=packed&page=2&keep=1');

    expect(await screen.findByText('26–30 of 30')).toBeTruthy();
    expect(bodyRows()[0].textContent).toContain('KO-151');
    expect(screen.getByLabelText('Status').value).toBe('packed');
    expect(screen.getByRole('columnheader', { name: 'Order' }).getAttribute('aria-sort')).toBe('ascending');
  });

  it('writes changes back to the URL, leaving defaults and other parameters alone', async () => {
    const router = renderAt('/orders?keep=1');
    await screen.findByRole('grid');
    expect(bodyRows()[0].textContent).toContain('KO-159');

    await act(async () => fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'pending' } }));
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Next page' })));
    expect(router.state.location.search).toEqual({ keep: 1, 'f.status': 'pending', page: 2 });

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Reset view' })));
    expect(router.state.location.search).toEqual({ keep: 1 });
  });
});
