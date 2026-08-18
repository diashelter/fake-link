import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { createSession, getSession } from './bff-session';
import { performBffResendVerification } from './bff-resend-verification';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const ACCEPTED_ENVELOPE = { message: 'Accepted.' };

function testConfig(): BffSessionConfig {
  return {
    aesKey: Buffer.alloc(32, 7),
    hmacKey: Buffer.alloc(32, 9),
    aesKeyId: '1',
    cookieName: '__Host-fl_session',
    redisUrl: 'redis://redis-ephemeral:6379',
    probeEnabled: false,
  };
}

describe('performBffResendVerification (EV-05–07, EV-11)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  function deps(fetchImpl: typeof fetch) {
    return { config, store, now: () => fixedNow, fetchImpl };
  }

  async function createKindSession(kind: 'session' | 'verification') {
    return createSession({ bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id }, {
      config,
      store,
      now: () => fixedNow,
    });
  }

  function makeRequest(
    options: {
      sessionId?: string;
      origin?: string;
      csrfToken?: string;
      includeSessionCookie?: boolean;
    } = {},
  ): Request {
    const sessionId = options.sessionId ?? 'unused-session';
    const csrfToken = options.csrfToken ?? deriveCsrfToken(sessionId);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`];
    if (options.includeSessionCookie !== false && options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }

    return new Request('https://app.localhost/api/bff/auth/email/resend', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
      },
    });
  }

  function sessionCookieHeader(response: { headers: Headers }): string {
    const setCookie = response.headers.getSetCookie?.() ?? [];
    return (
      setCookie.find((value) => value.includes('__Host-fl_session=')) ??
      response.headers.get('set-cookie') ??
      ''
    );
  }

  it('passes through upstream 202 Accepted without clearing the session cookie (EV-05, EV-07)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(202);
    expect(await result.response.json()).toEqual(ACCEPTED_ENVELOPE);
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
    expect(del).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/email/verification-notification',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
        }),
      }),
    );
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit | undefined;
    expect(init?.body).toBeUndefined();

    const after = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });
    expect(after.context?.sessionId).toBe(created.sessionId);
  });

  it('forwards upstream 429 and Retry-After without destroying the session (EV-06)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' };
    const fetchMock = vi.fn(async () =>
      Response.json(payload, { status: 429, headers: { 'Retry-After': '120' } }),
    );

    const result = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(429);
    expect(await result.response.json()).toEqual(payload);
    expect(result.response.headers.get('Retry-After')).toBe('120');
    expect(del).not.toHaveBeenCalled();
  });

  it('returns 403 without calling Laravel when kind is session or guards fail (EV-07, EV-11)', async () => {
    const sessionKind = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const kindFail = await performBffResendVerification(
      makeRequest({ sessionId: sessionKind.sessionId }),
      deps(fetchMock),
    );
    expect(kindFail.response.status).toBe(403);
    expect(await kindFail.response.json()).toEqual({ message: 'Forbidden.' });

    const verification = await createKindSession('verification');
    const originFail = await performBffResendVerification(
      makeRequest({ sessionId: verification.sessionId, origin: 'https://evil.com' }),
      deps(fetchMock),
    );
    expect(originFail.response.status).toBe(403);
    expect(await originFail.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards 403 ACCOUNT_SUSPENDED without destroying the session', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
  });

  it('sets Cache-Control private, no-store on resend success (EV-05)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('omits the session Bearer from success and error JSON (EV-19)', async () => {
    const created = await createKindSession('verification');

    const success = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }))),
    );
    expect(JSON.stringify(await success.response.json())).not.toContain(FIXTURE_BEARER);

    const error = await performBffResendVerification(
      makeRequest({ sessionId: created.sessionId }),
      deps(
        vi.fn(async () =>
          Response.json({ code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' }, { status: 403 }),
        ),
      ),
    );
    expect(JSON.stringify(await error.response.json())).not.toContain(FIXTURE_BEARER);
  });
});
