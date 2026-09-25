import { firstTranslation } from '../../lib/translate.js';
import { useAuth } from '../auth/AuthProvider.jsx';

export { firstTranslation };

const CAN_EDIT = ['ROLE_ADMIN', 'ROLE_OPERATOR'];

/** Viewers read the catalog; operators and admins change it. The API enforces the same. */
export function useCanEditCatalog() {
  const { user } = useAuth();

  return user?.roles?.some((role) => CAN_EDIT.includes(role)) ?? false;
}

/**
 * A 422's violations as `{ field: message }`, in the UI's language where the
 * code is known: `catalog.violation.<field>.<code>`, then
 * `catalog.violation.<code>`, then the server's English message. `values`
 * fill the messages' placeholders.
 */
export function serverFieldErrors(error, t, values) {
  return Object.fromEntries(
    (error?.status === 422 ? error.violations : []).map((violation) => [
      violation.path,
      firstTranslation(t, [`catalog.violation.${violation.path}.${violation.code}`, `catalog.violation.${violation.code}`], violation.message, values),
    ]),
  );
}

export const SKU_PATTERN = /^\S{1,64}$/u;
export const BARCODE_PATTERN = /^\S{1,64}$/u;
export const MAX_WEIGHT_GRAMS = 10_000_000;
export const LOCATION_CODE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/;

/** Blank is null; anything but whole grams is NaN, so the form can say so. */
export function parseGrams(text) {
  const trimmed = text.trim();
  if (trimmed === '') return null;

  return /^\d{1,9}$/.test(trimmed) ? Number(trimmed) : Number.NaN;
}

export function blankToNull(text) {
  const trimmed = text.trim();

  return trimmed === '' ? null : trimmed;
}
