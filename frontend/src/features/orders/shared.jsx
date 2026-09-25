import { useMemo } from 'react';
import { Badge } from '../../components/ui/primitives.jsx';
import { useAuth } from '../auth/AuthProvider.jsx';
import { useI18n } from '../../lib/i18n.jsx';

const TONES = { shipped: 'green', delivered: 'green', on_hold: 'amber', cancelled: 'amber' };

export function StatusBadge({ status }) {
  const { t } = useI18n();

  return <Badge tone={TONES[status] ?? 'slate'}>{t(`orderStatus.${status}`)}</Badge>;
}

/** Operators and admins change orders; viewers only read. The API enforces the same. */
export function useCanOperate() {
  const { user } = useAuth();

  return Boolean(user?.roles?.some((role) => role === 'ROLE_OPERATOR' || role === 'ROLE_ADMIN'));
}

export function useDateTime() {
  const { locale } = useI18n();

  return useMemo(() => {
    const format = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });

    return (instant) => format.format(new Date(instant));
  }, [locale]);
}
