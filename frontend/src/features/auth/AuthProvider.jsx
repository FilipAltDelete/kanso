import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { auth } from '../../api/client.js';

export const AuthContext = createContext(null);

/**
 * No server session exists at any point: a reload has nothing but the refresh
 * cookie, so the app starts by trying to trade it for an access token.
 */
export function AuthProvider({ children }) {
  const [status, setStatus] = useState('restoring');
  const [user, setUser] = useState(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        await auth.restore();
        const me = await auth.me();
        if (!cancelled) {
          setUser(me);
          setStatus('authenticated');
        }
      } catch {
        if (!cancelled) setStatus('anonymous');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(async (email, password) => {
    await auth.login(email, password);
    setUser(await auth.me());
    setStatus('authenticated');
  }, []);

  const logout = useCallback(async () => {
    await auth.logout();
    setUser(null);
    setStatus('anonymous');
  }, []);

  const value = useMemo(() => ({ status, user, login, logout }), [status, user, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === null) throw new Error('useAuth must be used inside an AuthProvider.');

  return context;
}
