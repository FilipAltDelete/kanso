import { useMemo } from 'react';
import { TAG_MAX_LENGTH } from '../../api/orders.js';
import { Badge } from '../../components/ui/primitives.jsx';
import { cn } from '../../lib/utils.js';
import { useAuth } from '../auth/AuthProvider.jsx';
import { useI18n } from '../../lib/i18n.jsx';

const TONES = { shipped: 'green', delivered: 'green', on_hold: 'amber', cancelled: 'amber' };

export function StatusBadge({ status }) {
  const { t } = useI18n();

  return <Badge tone={TONES[status] ?? 'slate'}>{t(`orderStatus.${status}`)}</Badge>;
}

const PAYMENT_TONES = { paid: 'green', refunded: 'amber', partially_refunded: 'amber' };

export function PaymentBadge({ status }) {
  const { t } = useI18n();

  return <Badge tone={PAYMENT_TONES[status] ?? 'slate'}>{t(`paymentStatus.${status}`)}</Badge>;
}

/** An order's tags as a list, for the table and the order page. */
export function TagList({ tags, className }) {
  if (tags.length === 0) return null;

  return (
    <ul className={cn('flex flex-wrap gap-1', className)}>
      {tags.map((tag) => (
        <li key={tag}>
          <Badge>{tag}</Badge>
        </li>
      ))}
    </ul>
  );
}

/** The API's rule for a tag name, checked before sending: 1–64 characters, no comma. */
export function normalizeTag(value) {
  const tag = value.trim().replace(/\s+/g, ' ');

  // Counted in characters, as the API counts them, not UTF-16 units.
  return tag.length > 0 && [...tag].length <= TAG_MAX_LENGTH && !tag.includes(',') ? tag : null;
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
