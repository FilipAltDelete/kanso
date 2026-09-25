import { useId, useState } from 'react';
import { X } from 'lucide-react';
import { NOTE_MAX_LENGTH, PAYMENT_STATUSES, useAddNote, useChangePaymentStatus, useChangeTags, useOrderTags } from '../../api/orders.js';
import { Button, Card, ErrorNotice, Field, Input, Select } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { normalizeTag, PaymentBadge, TagList, useCanOperate } from './shared.jsx';

/**
 * The payment status, set by hand. It is part of the order, so it is sent
 * with the version the page shows: a conflict reloads the order.
 */
export function PaymentCard({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const change = useChangePaymentStatus(order.id);
  const [draft, setDraft] = useState(null);
  const value = draft ?? order.paymentStatus;

  function submit(event) {
    event.preventDefault();
    if (value === order.paymentStatus) return;
    change.mutate({ paymentStatus: value, version: order.version }, { onSettled: () => setDraft(null) });
  }

  return (
    <Card className="space-y-2 p-4">
      <h2 className="text-sm font-semibold text-slate-900">{t('order.paymentStatus')}</h2>
      {canOperate ? (
        <form onSubmit={submit} className="space-y-2">
          <Field label={t('orders.payment.label')} hint={t('orders.payment.hint')}>
            {(props) => (
              <Select {...props} className="w-full" value={value} onChange={(event) => setDraft(event.target.value)}>
                {PAYMENT_STATUSES.map((status) => (
                  <option key={status} value={status}>
                    {t(`paymentStatus.${status}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Button type="submit" size="sm" disabled={value === order.paymentStatus || change.isPending}>
            {t('orders.payment.save')}
          </Button>
          {change.error?.status === 409 ? (
            <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              {t('orders.changedElsewhere')}
            </p>
          ) : change.error ? (
            <ErrorNotice error={change.error} />
          ) : null}
        </form>
      ) : (
        <p>
          <PaymentBadge status={order.paymentStatus} />
        </p>
      )}
    </Card>
  );
}

/** Tags are added and removed by name; no version, so they never conflict with other changes. */
export function TagsCard({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const change = useChangeTags(order.id);
  // Suggestions are fetched once someone is about to type a tag, not with every order page.
  const [suggest, setSuggest] = useState(false);
  const known = useOrderTags({ enabled: suggest });
  const listId = useId();
  const [draft, setDraft] = useState('');
  const [invalid, setInvalid] = useState(false);

  function add(event) {
    event.preventDefault();
    const tag = normalizeTag(draft);
    setInvalid(tag === null);
    if (tag === null) return;
    change.mutate({ add: [tag] }, { onSuccess: () => setDraft('') });
  }

  return (
    <Card className="space-y-2 p-4">
      <h2 className="text-sm font-semibold text-slate-900">{t('order.tags')}</h2>
      {order.tags.length === 0 ? <p className="text-sm text-slate-600">{t('orders.tags.none')}</p> : null}
      {canOperate ? (
        <ul className="flex flex-wrap gap-1">
          {order.tags.map((tag) => (
            <li key={tag} className="inline-flex items-center gap-0.5 rounded bg-slate-100 py-0.5 pl-1.5 pr-0.5 text-xs font-medium text-slate-700">
              {tag}
              <button
                type="button"
                className="rounded p-0.5 hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-slate-900"
                aria-label={t('orders.tags.remove', { tag })}
                disabled={change.isPending}
                onClick={() => change.mutate({ remove: [tag] })}
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <TagList tags={order.tags} />
      )}
      {canOperate ? (
        <form onSubmit={add} noValidate className="flex items-end gap-2">
          <div className="flex-1">
            <Field label={t('orders.tags.newLabel')} error={invalid ? t('orders.tags.invalid') : null}>
              {(props) => <Input {...props} list={listId} autoComplete="off" onFocus={() => setSuggest(true)} value={draft} onChange={(event) => setDraft(event.target.value)} />}
            </Field>
          </div>
          <Button type="submit" size="sm" variant="outline" className="mb-0.5" disabled={change.isPending}>
            {t('orders.tags.add')}
          </Button>
          <datalist id={listId}>
            {(known.data ?? [])
              .filter(({ name }) => !order.tags.some((tag) => tag.toLowerCase() === name.toLowerCase()))
              .map(({ name }) => (
                <option key={name} value={name} />
              ))}
          </datalist>
        </form>
      ) : null}
      {change.error ? <ErrorNotice error={change.error} /> : null}
    </Card>
  );
}

/** A free-text note, in any status. It shows up in the history below with who wrote it and when. */
export function NoteForm({ order }) {
  const { t } = useI18n();
  const canOperate = useCanOperate();
  const addNote = useAddNote(order.id);
  const [note, setNote] = useState('');
  const [touched, setTouched] = useState(false);

  if (!canOperate) return null;

  const empty = note.trim() === '';

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    if (empty || addNote.isPending) return;
    addNote.mutate(
      { note: note.trim() },
      {
        onSuccess: () => {
          setNote('');
          setTouched(false);
        },
      },
    );
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-2">
      <Field label={t('orders.notes.label')} hint={t('orders.notes.hint')} error={touched && empty ? t('orders.notes.empty') : null}>
        {(props) => (
          <textarea
            {...props}
            rows={3}
            maxLength={NOTE_MAX_LENGTH}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            onKeyDown={(event) => {
              // Ctrl/Cmd+Enter saves, as in most note fields; Enter alone is a new line.
              if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) submit(event);
            }}
            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
          />
        )}
      </Field>
      <Button type="submit" size="sm" disabled={addNote.isPending}>
        {t('orders.notes.add')}
      </Button>
      {addNote.error ? <ErrorNotice error={addNote.error} /> : null}
    </form>
  );
}
