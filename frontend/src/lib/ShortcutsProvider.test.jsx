import { fireEvent, render, screen, within } from '@testing-library/react';
import { I18nProvider } from './i18n.jsx';
import { ShortcutsProvider, useShortcutHelp, useShortcuts } from './ShortcutsProvider.jsx';

function Page({ handlers }) {
  useShortcuts(handlers);
  const openHelp = useShortcutHelp();

  return (
    <div>
      <label>
        Name
        <input />
      </label>
      <button type="button" onClick={openHelp}>
        Help
      </button>
    </div>
  );
}

function renderPage(handlers, { locale = 'en', inner } = {}) {
  return render(
    <I18nProvider locale={locale}>
      <ShortcutsProvider>
        <Page handlers={handlers} />
        {inner}
      </ShortcutsProvider>
    </I18nProvider>,
  );
}

const press = (key, target = document.body, init = {}) => fireEvent.keyDown(target, { key, ...init });

describe('keyboard shortcuts', () => {
  beforeAll(() => {
    HTMLDialogElement.prototype.showModal ??= function showModal() {
      this.open = true;
    };
    HTMLDialogElement.prototype.close ??= function close() {
      this.open = false;
      this.dispatchEvent(new Event('close'));
    };
  });

  beforeEach(() => localStorage.clear());

  it('runs the handler for a key and for a sequence', () => {
    const handlers = { 'orders.new': vi.fn(), goOrders: vi.fn() };
    renderPage(handlers);

    press('n');
    expect(handlers['orders.new']).toHaveBeenCalledTimes(1);

    press('g');
    expect(handlers.goOrders).not.toHaveBeenCalled();
    press('o');
    expect(handlers.goOrders).toHaveBeenCalledTimes(1);
  });

  it('ignores keys typed into a field, and keys with Ctrl or Cmd', () => {
    const handlers = { 'orders.new': vi.fn() };
    renderPage(handlers);

    press('n', screen.getByLabelText('Name'));
    press('n', document.body, { ctrlKey: true });
    press('n', document.body, { metaKey: true });
    expect(handlers['orders.new']).not.toHaveBeenCalled();
  });

  it('takes the key from the browser only when the handler did something', () => {
    renderPage({ search: () => false, 'orders.new': () => undefined });

    expect(press('/')).toBe(true);
    expect(press('n')).toBe(false);
  });

  it('prefers the newest handler, and falls back when it goes', () => {
    const outer = vi.fn();
    const inner = vi.fn();
    function Inner() {
      useShortcuts({ 'order.back': inner });
      return null;
    }
    const { rerender } = renderPage({ 'order.back': outer }, { inner: <Inner /> });

    press('u');
    expect(inner).toHaveBeenCalledTimes(1);
    expect(outer).not.toHaveBeenCalled();

    rerender(
      <I18nProvider locale="en">
        <ShortcutsProvider>
          <Page handlers={{ 'order.back': outer }} />
        </ShortcutsProvider>
      </I18nProvider>,
    );
    press('u');
    expect(outer).toHaveBeenCalledTimes(1);
  });

  it('lists the shortcuts on "?", from the registry, with this page’s own section', () => {
    renderPage({ 'orders.new': vi.fn(), 'orders.tagSelected': vi.fn() }, { locale: 'sv' });

    press('?', document.body, { shiftKey: true });
    const dialog = screen.getByRole('dialog', { name: 'Kortkommandon' });
    const everywhere = within(dialog).getByRole('region', { name: 'Överallt' });
    const goOrders = within(everywhere).getByText('Gå till ordrar').closest('div');
    expect(goOrders.textContent).toBe('Gå till ordrargsedano');
    expect(within(dialog).getByRole('region', { name: 'Orderlistan' })).toBeTruthy();
    expect(within(dialog).getByRole('region', { name: 'Listor' })).toBeTruthy();
    // Another page's shortcuts are not on this one.
    expect(within(dialog).queryByRole('region', { name: 'Order' })).toBeNull();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Stäng' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('does nothing while a dialog is open', () => {
    const handlers = { 'orders.new': vi.fn() };
    renderPage(handlers);

    fireEvent.click(screen.getByRole('button', { name: 'Help' }));
    press('n', screen.getByRole('dialog'));
    expect(handlers['orders.new']).not.toHaveBeenCalled();
  });

  it('can be switched off, and remembers that', () => {
    const handlers = { 'orders.new': vi.fn() };
    const { unmount } = renderPage(handlers);

    fireEvent.click(screen.getByRole('button', { name: 'Help' }));
    const toggle = screen.getByRole('checkbox', { name: 'Single-key shortcuts' });
    expect(toggle.checked).toBe(true);
    fireEvent.click(toggle);
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));

    press('n');
    expect(handlers['orders.new']).not.toHaveBeenCalled();

    unmount();
    renderPage(handlers);
    press('n');
    expect(handlers['orders.new']).not.toHaveBeenCalled();
    // The help still opens from its button, to switch them back on.
    fireEvent.click(screen.getByRole('button', { name: 'Help' }));
    expect(screen.getByRole('checkbox', { name: 'Single-key shortcuts' }).checked).toBe(false);
  });
});
