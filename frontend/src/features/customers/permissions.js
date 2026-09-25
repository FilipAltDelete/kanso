import { useAuth } from '../auth/AuthProvider.jsx';

/** Mirrors the API: reading customers needs a sign-in, changing them the operator role. */
export function useCanEditCustomers() {
  const { user } = useAuth();

  return Boolean(user?.roles?.some((role) => role === 'ROLE_OPERATOR' || role === 'ROLE_ADMIN'));
}
