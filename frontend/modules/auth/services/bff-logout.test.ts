import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_SID_COOKIE, CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import {
  getLogoutRedisFailCount,
  getLogoutUpstreamFailCount,
} from '@/modules/auth/lib/session/metrics';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { performBffLogout } from './bff-logout';
import * as bffSession from './bff-session';
import { createSession } from './bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const LOGOUT_SUCCESS_MESSAGE = 'Você saiu da conta.';
const LOGOUT_SUCCESS_BODY = {
  data: { redirect_to: '/login', message: LOGOUT_SUCCESS_MESSAGE },
};

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

describe('performBffLogout (SH-01–05, SH-21)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');
  let destroySessionSpy: ReturnType<typeof vi.spyOn>;
  let clearSessionCookieSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    destroySessionSpy = vi.spyOn(bffSession, 'destroySession') as typeof destroySessionSpy;
    clearSessionCookieSpy = vi.spyOn(
      bffSession,
      'clearSessionCookie',
    ) as typeof clearSessionCookieSpy;
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  function deps(fetchImpl: typeof fetch) {
    return { config, store, now: () => fixedNow, fetchImpl };
  }

  async function createKindSession(kind: 'session' | 'verification') {
    return createSession(
      { bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id },
      { config, store, now: () => fixedNow },
    );
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

    return new Request('https://app.localhost/api/bff/auth/logout', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
      },
    });
  }

  function setCookies(response: { headers: Headers }): string[] {
    return response.headers.getSetCookie?.() ?? [];
  }

  function sessionCookieHeader(response: { headers: Headers }): string {
    return (
      setCookies(response).find((value) => value.includes('__Host-fl_session=')) ??
      response.headers.get('set-cookie') ??
      ''
    );
  }

  it('translates upstream 204 into 200, clears session/CSRF cookies, and destroys the session (SH-01)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(destroySessionSpy).toHaveBeenCalledWith(created.sessionId, expect.anything());
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    const cookies = setCookies(result.response);
    expect(cookies.find((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`))).toMatch(/Max-Age=0/i);
    expect(cookies.find((value) => value.startsWith(`${CSRF_SID_COOKIE}=`))).toMatch(/Max-Age=0/i);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/logout',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
        }),
      }),
    );
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect(init.body).toBeUndefined();
  });

  it('does not return 403 when session kind is verification (SH-05)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(destroySessionSpy).toHaveBeenCalledWith(created.sessionId, expect.anything());
  });

  it('returns 200 and increments redis counter when destroySession fails after Laravel 204 (SH-02)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    destroySessionSpy.mockRejectedValueOnce(new Error('redis down'));
    const redisBefore = getLogoutRedisFailCount();
    const upstreamBefore = getLogoutUpstreamFailCount();

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(fetchMock).toHaveBeenCalledOnce();
    expect(getLogoutRedisFailCount()).toBe(redisBefore + 1);
    expect(getLogoutUpstreamFailCount()).toBe(upstreamBefore);
  });

  it('returns 200 and increments upstream counter on Laravel timeout, still destroying and clearing (SH-03)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });
    const upstreamBefore = getLogoutUpstreamFailCount();
    const redisBefore = getLogoutRedisFailCount();

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(destroySessionSpy).toHaveBeenCalledWith(created.sessionId, expect.anything());
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(getLogoutUpstreamFailCount()).toBe(upstreamBefore + 1);
    expect(getLogoutRedisFailCount()).toBe(redisBefore);
  });

  it('returns 200 and increments upstream counter on Laravel 5xx, still destroying and clearing (SH-03)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );
    const upstreamBefore = getLogoutUpstreamFailCount();

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(destroySessionSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(getLogoutUpstreamFailCount()).toBe(upstreamBefore + 1);
  });

  it('returns 200 without fetching when the session is a miss (SH-04)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    const cookies = setCookies(result.response);
    expect(cookies.find((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`))).toMatch(/Max-Age=0/i);
    expect(cookies.find((value) => value.startsWith(`${CSRF_SID_COOKIE}=`))).toMatch(/Max-Age=0/i);
  });

  it('returns 403 without clearing cookies when Origin is invalid (SH-05)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId, origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('returns 403 without clearing cookies when CSRF is invalid and a session is resolvable (SH-05)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId, csrfToken: 'bad-token' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('returns 200 local success for Laravel 401 without incrementing the upstream counter (SH-01)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json({ code: 'UNAUTHENTICATED', message: 'Unauthenticated.' }, { status: 401 }),
    );
    const upstreamBefore = getLogoutUpstreamFailCount();

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(destroySessionSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(getLogoutUpstreamFailCount()).toBe(upstreamBefore);
  });

  it('returns 200 local success for Laravel 429 without incrementing the upstream counter', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );
    const upstreamBefore = getLogoutUpstreamFailCount();

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(LOGOUT_SUCCESS_BODY);
    expect(destroySessionSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(getLogoutUpstreamFailCount()).toBe(upstreamBefore);
  });

  it('omits Bearer sentinel from success JSON (SH-21)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffLogout(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );
    const serialized = JSON.stringify(await result.response.json());

    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain('token');
    expect(serialized).not.toContain('token_kind');
    expect(serialized).not.toContain('token_type');
    expect(serialized).not.toContain('expires_at');
  });
});
