import { ApiError, NetworkError } from '../api/client.js';
import { errorMessage } from './errorMessage.js';
import { translate } from './i18n.jsx';

const t = (locale) => (key, values) => translate(locale, key, values);

describe('what an error says', () => {
  it('is the API’s own detail or title when it sent one', () => {
    expect(errorMessage(new ApiError(422, { title: 'Unprocessable', detail: 'The SKU is taken.' }), t('sv'))).toBe('The SKU is taken.');
    expect(errorMessage(new ApiError(403, { title: 'Forbidden' }), t('sv'))).toBe('Forbidden');
  });

  it('is in the person’s language when the API said nothing, or could not be reached', () => {
    expect(errorMessage(new ApiError(502, null), t('sv'))).toBe('Begäran misslyckades (502).');
    expect(errorMessage(new ApiError(502, null), t('en'))).toBe('The request failed (502).');
    expect(errorMessage(new NetworkError(new TypeError('Failed to fetch')), t('en'))).toBe('Could not reach the server. Check the connection and try again.');
  });

  it('is the error’s own message otherwise, and a generic one when there is none', () => {
    expect(errorMessage({ message: 'Fix the marked fields.' }, t('en'))).toBe('Fix the marked fields.');
    expect(errorMessage(null, t('sv'))).toBe('Något gick fel.');
  });
});
