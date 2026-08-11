import { describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { mapTokenKindToSessionKind, parseUpstreamAuthResponse } from './auth-api-types';
import { FIXTURE_USER, buildUpstreamAuthPayload, FIXTURE_BEARER } from './test/auth-fixtures';

describe('parseUpstreamAuthResponse', () => {
  it('parses valid AuthResponse', () => {
    const parsed = parseUpstreamAuthResponse(buildUpstreamAuthPayload());
    expect(parsed).not.toBeNull();
    expect(parsed!.token).toBe(FIXTURE_BEARER);
    expect(parsed!.token_kind).toBe('session');
    expect(parsed!.user.email).toBe('user@example.com');
  });

  it('returns null when data.token is missing', () => {
    expect(parseUpstreamAuthResponse({ data: { user: FIXTURE_USER } })).toBeNull();
  });

  it('returns null when token is empty', () => {
    expect(parseUpstreamAuthResponse(buildUpstreamAuthPayload({ token: '   ' }))).toBeNull();
  });

  it('returns null for unknown token_kind', () => {
    expect(
      parseUpstreamAuthResponse(buildUpstreamAuthPayload({ token_kind: 'unknown' as 'session' })),
    ).toBeNull();
  });

  it('returns null when user shape is invalid', () => {
    expect(
      parseUpstreamAuthResponse(
        buildUpstreamAuthPayload({ user: { ...FIXTURE_USER, status: 'invalid' as 'active' } }),
      ),
    ).toBeNull();
  });

  it('maps token_kind to session kind', () => {
    expect(mapTokenKindToSessionKind('session')).toBe('session');
    expect(mapTokenKindToSessionKind('verification')).toBe('verification');
  });
});
