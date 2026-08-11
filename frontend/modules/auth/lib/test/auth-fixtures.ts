export const FIXTURE_USER = {
  id: '019082da-0000-7000-8000-000000000001',
  name: 'Test User',
  email: 'user@example.com',
  status: 'active' as const,
  email_verified_at: '2026-01-01T00:00:00.000Z',
  terms_version: '1.0',
  terms_accepted_at: '2026-01-01T00:00:00.000Z',
  created_at: '2026-01-01T00:00:00.000Z',
  updated_at: '2026-01-01T00:00:00.000Z',
};

export const FIXTURE_BEARER = 'fixture-bearer-token-PLAINTEXT-do-not-leak-xyz';

export function buildUpstreamAuthPayload(
  overrides: Partial<{
    token: string;
    token_kind: 'session' | 'verification' | 'unknown';
    user: typeof FIXTURE_USER;
  }> = {},
) {
  return {
    data: {
      token: overrides.token ?? FIXTURE_BEARER,
      token_type: 'Bearer',
      token_kind: overrides.token_kind ?? 'session',
      expires_at: '2026-08-12T12:00:00.000Z',
      user: overrides.user ?? FIXTURE_USER,
    },
  };
}
