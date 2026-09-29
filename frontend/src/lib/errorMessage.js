/**
 * What an error says to the person reading it. The API's problem detail or
 * title when it sent one (the server's own words, as before); a sentence in
 * their language when a request failed without saying why, or never reached
 * the server; otherwise the error's own message, else a generic one.
 */
export function errorMessage(error, t) {
  if (error?.name === 'NetworkError') return t('error.network');

  const problem = error?.problem;
  if (problem && typeof error.status === 'number' && !problem.detail && !problem.title) return t('error.requestFailed', { status: error.status });

  return error?.message || t('error.generic');
}
