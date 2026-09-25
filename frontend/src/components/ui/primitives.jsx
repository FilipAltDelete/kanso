import { useEffect, useId, useRef } from 'react';
import { cn } from '../../lib/utils.js';

export function Button({ className, variant = 'default', size = 'default', ...props }) {
  const variants = {
    default: 'bg-accent text-accent-fg hover:bg-accent-hover',
    outline: 'border border-slate-300 bg-white text-slate-900 hover:bg-slate-50',
    ghost: 'text-slate-700 hover:bg-slate-100',
    danger: 'bg-red-600 text-white hover:bg-red-700',
  };
  const sizes = { default: 'h-9 px-4 text-sm', sm: 'h-8 px-3 text-xs', icon: 'h-9 w-9' };

  return (
    <button
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
        'disabled:pointer-events-none disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    />
  );
}

export function Input({ className, ...props }) {
  return (
    <input
      className={cn(
        'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm',
        'placeholder:text-slate-400 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900',
        className,
      )}
      {...props}
    />
  );
}

export function Select({ className, ...props }) {
  return (
    <select
      className={cn('h-9 rounded-md border border-slate-300 bg-white px-2 text-sm focus-visible:outline-2 focus-visible:outline-slate-900', className)}
      {...props}
    />
  );
}

/**
 * A real checkbox, styled. Row selection has to survive the keyboard and a
 * screen reader, and `accent-color` is the whole styling budget a native
 * checkbox needs.
 */
export function Checkbox({ className, ...props }) {
  return (
    <input
      type="checkbox"
      className={cn(
        'size-4 shrink-0 cursor-pointer rounded border-slate-300 accent-slate-900',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
        className,
      )}
      {...props}
    />
  );
}

export function Card({ className, ...props }) {
  return <div className={cn('rounded-lg border border-slate-200 bg-white shadow-sm', className)} {...props} />;
}

export function Badge({ className, tone = 'slate', ...props }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700',
    green: 'bg-green-100 text-green-800',
    amber: 'bg-amber-100 text-amber-700',
    // A count of things that need someone's attention, as in the menu.
    alert: 'bg-red-50 text-red-600 tabular-nums',
  };

  return <span className={cn('inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium', tones[tone], className)} {...props} />;
}

/** The shape of a row before its data arrives, so the grid does not jump. */
export function Skeleton({ className }) {
  return <div className={cn('h-3 animate-pulse rounded bg-slate-100', className)} />;
}

export function Spinner({ label = 'Loading' }) {
  return (
    <div className="flex items-center gap-2 text-sm text-slate-500" role="status">
      <span className="size-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700" />
      {label}
    </div>
  );
}

export function EmptyState({ title, children }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-300 p-8 text-center">
      <p className="font-medium text-slate-700">{title}</p>
      {children ? <div className="mt-1 text-sm text-slate-500">{children}</div> : null}
    </div>
  );
}

export function ErrorNotice({ error }) {
  return (
    <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
      {error?.message ?? 'Something went wrong.'}
      {error?.violations?.length ? (
        <ul className="mt-1 list-inside list-disc">
          {error.violations.map((violation) => (
            <li key={`${violation.path}-${violation.message}`}>
              {violation.path ? <span className="font-mono text-xs">{violation.path}</span> : null} {violation.message}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/**
 * A labelled form control with its hint and error wired up for assistive
 * technology. `children` is a render function that receives the props the
 * control needs: `{ id, aria-invalid, aria-describedby, aria-required }`.
 */
export function Field({ label, hint, error, required = false, requiredLabel, children }) {
  const id = useId();
  const described = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs font-medium text-slate-600">
        {label}
        {required ? <span className="sr-only"> ({requiredLabel})</span> : null}
        {required ? <span aria-hidden="true"> *</span> : null}
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': described, 'aria-required': required || undefined })}
      {hint ? (
        <p id={`${id}-hint`} className="text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-xs text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

/**
 * A modal on the native <dialog>: the browser traps focus, Escape closes it,
 * and focus returns to what opened it. It opens on mount; `onClose` runs
 * however it was closed. `dialogRef` lets the content close it.
 */
export function Dialog({ title, description, dialogRef, onClose, className, children }) {
  const ownRef = useRef(null);
  const ref = dialogRef ?? ownRef;
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, [ref]);

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onClose={onClose}
      className={cn('m-auto w-full max-w-md rounded-lg border border-slate-200 bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-900/40', className)}
    >
      <div className="space-y-4 p-5">
        <div>
          <h2 id={titleId} className="text-lg font-semibold">
            {title}
          </h2>
          {description ? <p className="text-sm text-slate-500">{description}</p> : null}
        </div>
        {children}
      </div>
    </dialog>
  );
}
