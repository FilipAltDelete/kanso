import { useId, useRef, useState } from 'react';
import { useBulkChangeTags, useOrderTags } from '../../api/orders.js';
import { Button, Dialog, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { normalizeTag } from './shared.jsx';

/**
 * Adds one tag to, or removes one from, the orders selected in the list,
 * including selected orders on other pages. The API applies it to all of
 * them or none.
 */
export function BulkTagDialog({ mode, orderIds, onClose, onDone }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const listId = useId();
  const known = useOrderTags();
  const change = useBulkChangeTags();
  const [draft, setDraft] = useState('');
  const [invalid, setInvalid] = useState(false);
  const count = new Intl.NumberFormat(locale).format(orderIds.length);

  function submit(event) {
    event.preventDefault();
    const tag = normalizeTag(draft);
    setInvalid(tag === null);
    if (tag === null || change.isPending) return;

    change.mutate(
      { orders: orderIds, ...(mode === 'add' ? { add: [tag] } : { remove: [tag] }) },
      {
        onSuccess: () => {
          onDone(tag);
          dialogRef.current?.close();
        },
      },
    );
  }

  return (
    <Dialog dialogRef={dialogRef} title={t(mode === 'add' ? 'orders.bulk.addTitle' : 'orders.bulk.removeTitle', { count })} onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <Field label={t('orders.bulk.tag')} error={invalid ? t('orders.tags.invalid') : null}>
          {(props) => <Input {...props} list={listId} autoComplete="off" value={draft} onChange={(event) => setDraft(event.target.value)} />}
        </Field>
        <datalist id={listId}>
          {(known.data ?? []).map(({ name }) => (
            <option key={name} value={name} />
          ))}
        </datalist>

        {change.error ? <ErrorNotice error={change.error} /> : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={change.isPending}>
            {t(mode === 'add' ? 'orders.bulk.addTag' : 'orders.bulk.removeTag')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
