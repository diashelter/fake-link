import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  AUTH_BFF_ALLOWLIST,
  buildUpstreamUrl,
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
  it('exports empty production table', () => {
    expect(AUTH_BFF_ALLOWLIST).toHaveLength(0);
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
