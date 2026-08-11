import { afterEach, describe, expect, it, vi } from 'vitest';

import { isMutationMethod, validateMutationOrigin } from './origin';

afterEach(() => {
  vi.unstubAllEnvs();
});

function makeRequest(method: string, headers: Record<string, string> = {}): Request {
  return new Request('https://app.localhost/api/bff/test', {
    method,
    headers,
  });
}

describe('isMutationMethod', () => {
  it('treats POST PATCH DELETE as mutations', () => {
    expect(isMutationMethod('POST')).toBe(true);
    expect(isMutationMethod('PATCH')).toBe(true);
    expect(isMutationMethod('DELETE')).toBe(true);
  });

  it('does not treat GET as mutation', () => {
    expect(isMutationMethod('GET')).toBe(false);
  });
});

describe('validateMutationOrigin', () => {
  it('accepts exact origin match for mutations', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(
      validateMutationOrigin(makeRequest('POST', { Origin: 'https://app.localhost' })),
    ).toEqual({
      ok: true,
    });
  });

  it('rejects missing Origin on mutations', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(validateMutationOrigin(makeRequest('POST'))).toEqual({ ok: false });
  });

  it('rejects literal null Origin on mutations', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(validateMutationOrigin(makeRequest('POST', { Origin: 'null' }))).toEqual({ ok: false });
  });

  it('rejects wrong Origin on mutations', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(validateMutationOrigin(makeRequest('POST', { Origin: 'https://evil.com' }))).toEqual({
      ok: false,
    });
  });

  it('does not require Origin on GET by default', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(validateMutationOrigin(makeRequest('GET'))).toEqual({ ok: true });
  });

  it('ignores malicious Referer when Origin is valid', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(
      validateMutationOrigin(
        makeRequest('POST', {
          Origin: 'https://app.localhost',
          Referer: 'https://evil.com/x',
        }),
      ),
    ).toEqual({ ok: true });
  });

  it('rejects trailing slash mismatch as divergent', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');

    expect(
      validateMutationOrigin(makeRequest('POST', { Origin: 'https://app.localhost/' })),
    ).toEqual({ ok: false });
  });
});
