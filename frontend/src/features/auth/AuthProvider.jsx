import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { auth, onSessionEnded } from '../../api/client.js';

export const AuthContext = createContext(null);

/**
 * No server session exists at any point: a reload has nothing but the refresh
 * cookie, so the app starts by trying to trade it for an access token.
 *
 * A session that ends while the app is open — the refresh token refused
 * under some request (api/client.js) — signs the app out: the login page
 * comes back and says why, with the email filled in (`expired`). Whoever
 * signs in next starts from their own saved tabs, which for the same person
 * are the ones they had (ADR-0020).
 */
export function AuthProvider({ children }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('restoring');
  const [user, setUser] = useState(null);
  // `{ email }` of the user whose session ran out, until someone signs in.
  const [expired, setExpired] = useState(null);
  const latestUser = useRef(user);
  latestUser.current = user;

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

  useEffect(
    () =>
      onSessionEnded(() => {
        setExpired(latestUser.current ? { email: latestUser.current.email } : null);
        setUser(null);
        setStatus('anonymous');
      }),
    [],
  );

  // Signed out, by choice or not: nothing one person loaded is left for the
  // next. After the pages have gone, so none of them fetches it all again.
  useEffect(() => {
    if (status === 'anonymous') queryClient.clear();
  }, [status, queryClient]);

  const login = useCallback(async (email, password) => {
    await auth.login(email, password);
    setUser(await auth.me());
    setExpired(null);
    setStatus('authenticated');
  }, []);

  const logout = useCallback(async () => {
    await auth.logout();
    setUser(null);
    setExpired(null);
    setStatus('anonymous');
  }, []);

  const value = useMemo(() => ({ status, user, expired, login, logout }), [status, user, expired, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === null) throw new Error('useAuth must be used inside an AuthProvider.');

  return context;
}
