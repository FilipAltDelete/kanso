/**
 * The first translation that exists among `keys`, else `fallback`. t()
 * returns the key itself when there is no message for it, which is how a
 * missing one is told apart.
 */
export function firstTranslation(t, keys, fallback, values) {
  for (const key of keys) {
    const message = t(key, values);
    if (message !== key) return message;
  }

  return fallback;
}
