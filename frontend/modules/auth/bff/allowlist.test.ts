import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  AUTH_BFF_ALLOWLIST,
  buildUpstreamUrl,
  LOGIN_ALLOWLIST_ENTRY,
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
  it('exports exactly one production login entry (LOG-14)', () => {
    expect(AUTH_BFF_ALLOWLIST).toHaveLength(1);
    expect(AUTH_BFF_ALLOWLIST[0]).toEqual(LOGIN_ALLOWLIST_ENTRY);
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
