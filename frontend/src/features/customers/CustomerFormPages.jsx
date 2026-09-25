import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from '@tanstack/react-router';
import { customers } from '../../api/customers.js';
import { ErrorNotice, Spinner } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { CustomerForm } from './CustomerForm.jsx';

/** After a save: the lists and this customer are stale, and the saved customer is what the detail page shows. */
function useSaved() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  return (customer) => {
    queryClient.invalidateQueries({ queryKey: ['customers'] });
    queryClient.setQueryData(['customers', 'detail', customer.id], customer);
    navigate({ to: '/customers/$customerId', params: { customerId: customer.id } });
  };
}

export function NewCustomerPage() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const saved = useSaved();
  const create = useMutation({ mutationFn: customers.create, onSuccess: saved });

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">{t('customers.new')}</h1>
      <CustomerForm
        submitLabel={t('customers.create')}
        submitting={create.isPending}
        error={create.error}
        onSubmit={(payload) => create.mutate(payload)}
        onCancel={() => navigate({ to: '/customers' })}
      />
    </div>
  );
}

export function EditCustomerPage() {
  const { customerId } = useParams({ strict: false });
  const { t } = useI18n();
  const navigate = useNavigate();
  const saved = useSaved();
  const customer = useQuery({
    queryKey: ['customers', 'detail', customerId],
    queryFn: ({ signal }) => customers.get(customerId, signal),
  });
  const update = useMutation({ mutationFn: (payload) => customers.update(customerId, payload), onSuccess: saved });

  if (customer.isPending) return <Spinner label={t('common.loading')} />;
  if (customer.error) return <ErrorNotice error={customer.error.status === 404 ? { message: t('customers.notFound') } : customer.error} />;

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">{t('customers.editTitle', { name: customer.data.name })}</h1>
      <CustomerForm
        // A fresh form per loaded version, so a refetch never leaves stale fields behind.
        key={customer.data.updatedAt}
        initial={customer.data}
        submitLabel={t('form.save')}
        submitting={update.isPending}
        error={update.error}
        onSubmit={(payload) => update.mutate(payload)}
        onCancel={() => navigate({ to: '/customers/$customerId', params: { customerId } })}
      />
    </div>
  );
}
