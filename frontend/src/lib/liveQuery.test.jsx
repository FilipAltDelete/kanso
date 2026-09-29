import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen } from '@testing-library/react';
import { ShownTabProvider } from './frontTab.js';
import { LIVE_REFRESH_MS, useHoldLiveRefresh, useLiveQuery } from './liveQuery.js';

function Live({ queryKey, queryFn }) {
  const query = useLiveQuery({ queryKey, queryFn });
  return <p>fetched {query.data ?? 0}</p>;
}

function Hold({ queryKey = null }) {
  useHoldLiveRefresh({ queryKey });
  return null;
}

/** A page showing `['order', 'o1']` live, with whatever else `children` adds; `fetches()` counts its requests. */
function setup() {
  let count = 0;
  const queryFn = vi.fn(async () => ++count);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const page = ({ shown = true, children = null } = {}) => (
    <QueryClientProvider client={queryClient}>
      <ShownTabProvider value={shown}>
        <Live queryKey={['order', 'o1']} queryFn={queryFn} />
        {children}
      </ShownTabProvider>
    </QueryClientProvider>
  );
  const view = render(page());

  return { queryClient, fetches: () => queryFn.mock.calls.length, show: (options) => view.rerender(page(options)) };
}

const tick = () => act(() => vi.advanceTimersByTimeAsync(LIVE_REFRESH_MS));

beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));

afterEach(() => {
  vi.useRealTimers();
  focusManager.setFocused(undefined);
});

describe('a live query', () => {
  it('asks again every 30 seconds', async () => {
    const { fetches } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    await tick();
    expect(await screen.findByText('fetched 2')).toBeTruthy();
    await tick();
    expect(await screen.findByText('fetched 3')).toBeTruthy();
    expect(fetches()).toBe(3);
  });

  it('asks again when the window comes back into focus', async () => {
    setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });

    expect(await screen.findByText('fetched 2')).toBeTruthy();
  });

  it('holds still under a dialog, focus included, and catches up once it closes', async () => {
    const { fetches, show } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    show({ children: <Hold /> });
    await tick();
    await tick();
    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });
    await act(() => vi.advanceTimersByTimeAsync(10));
    expect(fetches()).toBe(1);

    // Closed after a minute: the data is older than one interval, so it is fetched now.
    show();
    expect(await screen.findByText('fetched 2')).toBeTruthy();
  });

  it('holds still under a form holding its own record, and not for another one', async () => {
    const { fetches, show } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    show({ children: <Hold queryKey={['order', 'o2']} /> });
    await tick();
    expect(await screen.findByText('fetched 2')).toBeTruthy();

    show({ children: <Hold queryKey={['order', 'o1']} /> });
    await tick();
    await tick();
    expect(fetches()).toBe(2);
  });

  it('still fetches what is invalidated while held: that is how a 409 shows the current record', async () => {
    const { queryClient, show } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();
    show({ children: <Hold /> });

    await act(() => queryClient.invalidateQueries({ queryKey: ['order', 'o1'] }));

    expect(await screen.findByText('fetched 2')).toBeTruthy();
  });

  it('keeps still in a tab behind another, and catches up when the tab is shown again', async () => {
    const { fetches, show } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    show({ shown: false });
    await tick();
    await tick();
    expect(fetches()).toBe(1);

    show({ shown: true });
    expect(await screen.findByText('fetched 2')).toBeTruthy();
  });

  it('does not ask twice when shown again soon after it last asked', async () => {
    const { fetches, show } = setup();
    expect(await screen.findByText('fetched 1')).toBeTruthy();

    show({ shown: false });
    await act(() => vi.advanceTimersByTimeAsync(LIVE_REFRESH_MS / 3));
    show({ shown: true });
    await act(() => vi.advanceTimersByTimeAsync(10));

    expect(fetches()).toBe(1);
  });
});
