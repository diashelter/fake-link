import type { UserStatus } from '../auth-api-types';

export type FixtureUser = {
  id: string;
  name: string;
  email: string;
  status: UserStatus;
  email_verified_at: string | null;
  terms_version: string;
  terms_accepted_at: string;
  created_at: string;
  updated_at: string;
};

export const FIXTURE_USER: FixtureUser = {
  id: '019082da-0000-7000-8000-000000000001',
  name: 'Test User',
  email: 'user@example.com',
  status: 'active',
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
    user: FixtureUser;
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
