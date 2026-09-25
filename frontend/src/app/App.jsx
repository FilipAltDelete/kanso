import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError } from '../api/client.js';
import { Spinner } from '../components/ui/primitives.jsx';
import { AuthProvider, useAuth } from '../features/auth/AuthProvider.jsx';
import { LoginPage } from '../features/auth/LoginPage.jsx';
import { I18nProvider, useI18n } from '../lib/i18n.jsx';
import { ShortcutsProvider } from '../lib/ShortcutsProvider.jsx';
import { Layout } from './Layout.jsx';
import { WorkspaceProvider } from './workspace/WorkspaceProvider.jsx';

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
  const { status, user } = useAuth();
  const { t } = useI18n();

  if (status === 'restoring') {
    return (
      <div className="flex min-h-full items-center justify-center">
        <Spinner label={t('auth.restoring')} />
      </div>
    );
  }

  if (status !== 'authenticated') return <LoginPage />;

  // Keyed by user: signing in as someone else starts from their tabs, not the last person's.
  return (
    <WorkspaceProvider key={user?.id} userId={user?.id ?? 'anonymous'}>
      <ShortcutsProvider>
        <Layout />
      </ShortcutsProvider>
    </WorkspaceProvider>
  );
}
