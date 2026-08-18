import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  AUTH_BFF_ALLOWLIST,
  buildUpstreamUrl,
  LOGIN_ALLOWLIST_ENTRY,
  PASSWORD_CHANGE_ALLOWLIST_ENTRY,
  PASSWORD_RESET_ALLOWLIST_ENTRY,
  PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY,
  REGISTER_ALLOWLIST_ENTRY,
  RESEND_VERIFICATION_ALLOWLIST_ENTRY,
  VERIFY_EMAIL_ALLOWLIST_ENTRY,
  lookupAllowlistEntry,
  type AllowlistEntry,
} from './allowlist';

afterEach(() => {
  vi.unstubAllEnvs();
});

const TEST_TABLE: readonly AllowlistEntry[] = [
  {
    method: 'POST',
    bffPath: '/api/bff/_probe/mutate',
    upstreamMethod: 'POST',
    upstreamPath: '/auth/login',
    requireSession: false,
    requireCsrf: true,
  },
];

describe('AUTH_BFF_ALLOWLIST', () => {
  it('exports login, register, verify, resend, and password production entries (PW-24)', () => {
    expect(AUTH_BFF_ALLOWLIST).toHaveLength(7);
    expect(AUTH_BFF_ALLOWLIST).toEqual(
      expect.arrayContaining([
        LOGIN_ALLOWLIST_ENTRY,
        REGISTER_ALLOWLIST_ENTRY,
        VERIFY_EMAIL_ALLOWLIST_ENTRY,
        RESEND_VERIFICATION_ALLOWLIST_ENTRY,
        PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY,
        PASSWORD_RESET_ALLOWLIST_ENTRY,
        PASSWORD_CHANGE_ALLOWLIST_ENTRY,
      ]),
    );
  });
});

describe('LOGIN_ALLOWLIST_ENTRY', () => {
  it('resolves login route with pre-auth CSRF and no session requirement', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/login');

    expect(entry).toEqual(LOGIN_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(false);
    expect(entry?.requireCsrf).toBe(true);
  });
});

describe('REGISTER_ALLOWLIST_ENTRY', () => {
  it('resolves register route with pre-auth CSRF and no session requirement (RGR-18)', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/register');

    expect(entry).toEqual(REGISTER_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(false);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/register');
  });
});

describe('VERIFY_EMAIL_ALLOWLIST_ENTRY (EV-20)', () => {
  it('resolves verify BFF path with session and CSRF required', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/email/verify');

    expect(entry).toEqual(VERIFY_EMAIL_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(true);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/email/verify');
  });
});

describe('RESEND_VERIFICATION_ALLOWLIST_ENTRY (EV-20)', () => {
  it('resolves resend BFF path with session and CSRF required', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/email/resend');

    expect(entry).toEqual(RESEND_VERIFICATION_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(true);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/email/verification-notification');
  });
});

describe('PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY (PW-24)', () => {
  it('resolves reset-request path with pre-auth CSRF and no session requirement', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/password/reset-request');

    expect(entry).toEqual(PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(false);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/password/reset-request');
  });
});

describe('PASSWORD_RESET_ALLOWLIST_ENTRY (PW-24)', () => {
  it('resolves reset path with pre-auth CSRF and no session requirement', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/password/reset');

    expect(entry).toEqual(PASSWORD_RESET_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(false);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/password/reset');
  });
});

describe('PASSWORD_CHANGE_ALLOWLIST_ENTRY (PW-24)', () => {
  it('resolves change path with session and CSRF required', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/auth/password/change');

    expect(entry).toEqual(PASSWORD_CHANGE_ALLOWLIST_ENTRY);
    expect(entry?.requireSession).toBe(true);
    expect(entry?.requireCsrf).toBe(true);
    expect(entry?.upstreamPath).toBe('/auth/password/change');
  });
});

describe('lookupAllowlistEntry', () => {
  it('resolves stub entry from in-memory test table', () => {
    const entry = lookupAllowlistEntry('POST', '/api/bff/_probe/mutate', TEST_TABLE);

    expect(entry).toEqual(TEST_TABLE[0]);
  });

  it('returns undefined when entry is missing', () => {
    expect(lookupAllowlistEntry('GET', '/api/bff/missing', TEST_TABLE)).toBeUndefined();
  });
});

describe('buildUpstreamUrl', () => {
  it('builds URL only from configured base and fixed upstream path', () => {
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');

    expect(buildUpstreamUrl(TEST_TABLE[0])).toBe('http://nginx/api/v1/auth/login');
  });
});
