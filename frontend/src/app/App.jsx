import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { ApiError } from '../api/client.js';
import { Spinner } from '../components/ui/primitives.jsx';
import { AuthProvider, useAuth } from '../features/auth/AuthProvider.jsx';
import { LoginPage } from '../features/auth/LoginPage.jsx';
import { I18nProvider, useI18n } from '../lib/i18n.jsx';
import { router } from './router.jsx';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => !(error instanceof ApiError) && failureCount < 2,
    },
  },
});

export function App() {
  return (
    <I18nProvider>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <Gate />
        </AuthProvider>
      </QueryClientProvider>
    </I18nProvider>
  );
}

function Gate() {
  const { status } = useAuth();
  const { t } = useI18n();

  if (status === 'restoring') {
    return (
      <div className="flex min-h-full items-center justify-center">
        <Spinner label={t('auth.restoring')} />
      </div>
    );
  }

  if (status !== 'authenticated') return <LoginPage />;

  return <RouterProvider router={router} />;
}
