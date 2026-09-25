import { currentUserSchema, tokenResponseSchema } from './schemas.js';

describe('API boundary schemas', () => {
  it('accepts a login response', () => {
    expect(tokenResponseSchema.parse({ accessToken: 'abc', expiresIn: 900, tokenType: 'Bearer' }).accessToken).toBe('abc');
  });

  it('rejects a login response without a token', () => {
    expect(() => tokenResponseSchema.parse({ expiresIn: 900, tokenType: 'Bearer' })).toThrow();
  });

  it('accepts a user without a display name', () => {
    const user = currentUserSchema.parse({ id: '1', email: 'ops@example.com', name: null, roles: ['ROLE_OPERATOR'] });
    expect(user.name).toBeNull();
  });
});
