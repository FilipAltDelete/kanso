import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { I18nProvider } from '../../lib/i18n.jsx';
import { AuthContext } from './AuthProvider.jsx';
import { LoginPage } from './LoginPage.jsx';

function renderLogin(login) {
  return render(
    <I18nProvider locale="en">
      <AuthContext.Provider value={{ status: 'anonymous', user: null, login, logout: vi.fn() }}>
        <LoginPage />
      </AuthContext.Provider>
    </I18nProvider>,
  );
}

describe('LoginPage', () => {
  it('signs in with the typed credentials', async () => {
    const login = vi.fn().mockResolvedValue(undefined);
    renderLogin(login);

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: ' ops@example.com ' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(login).toHaveBeenCalledWith('ops@example.com', 'secret'));
  });

  it('shows the problem detail when sign-in fails', async () => {
    renderLogin(vi.fn().mockRejectedValue(new Error('Wrong email or password.')));

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'ops@example.com' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'nope' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Wrong email or password.');
  });
});
