import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { performBffPasswordChange } from './bff-password-change';
import * as bffSession from './bff-session';
import { createSession } from './bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const CURRENT_PASSWORD_SENTINEL = 'old-secret-SENTINEL';
const PASSWORD_SENTINEL = 'Abcdefghij1!';
const CHANGE_SUCCESS_MESSAGE = 'Senha alterada. Faça login para continuar.';

const VALID_BODY = {
  current_password: CURRENT_PASSWORD_SENTINEL,
  password: PASSWORD_SENTINEL,
  password_confirmation: PASSWORD_SENTINEL,
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

describe('performBffPasswordChange (PW-12–14, PW-19–22)', () => {
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
    destroySessionSpy = vi.spyOn(bffSession, 'destroySession');
    clearSessionCookieSpy = vi.spyOn(bffSession, 'clearSessionCookie');
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
      body?: unknown;
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

    return new Request('https://app.localhost/api/bff/auth/password/change', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(options.body ?? VALID_BODY),
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

  it('translates upstream 204 into 200, destroys session, and redirects to /login (PW-12)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual({
      data: { redirect_to: '/login', message: CHANGE_SUCCESS_MESSAGE },
    });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(destroySessionSpy).toHaveBeenCalledWith(created.sessionId, expect.anything());
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/password/change',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify(VALID_BODY),
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
          'Content-Type': 'application/json',
        }),
      }),
    );
  });

  it('forwards 401 INVALID_CREDENTIALS without destroying the session (PW-14)', async () => {
    const created = await createKindSession('session');
    const payload = { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 401 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(401);
    expect(await result.response.json()).toEqual(payload);
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('forwards 422 PASSWORD_REUSED without destroying the session (PW-14)', async () => {
    const created = await createKindSession('session');
    const payload = {
      code: 'VALIDATION_FAILED',
      errors: {
        password: [{ code: 'PASSWORD_REUSED', message: 'The new password must be different.' }],
      },
    };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 422 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toEqual(payload);
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('returns 403 without fetching when session kind is verification (PW-13)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
  });

  it('returns 403 without fetching when Origin is invalid (PW-19)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId, origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('omits passwords and Bearer from success JSON (PW-22)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );
    const serialized = JSON.stringify(await result.response.json());

    expect(serialized).not.toContain(PASSWORD_SENTINEL);
    expect(serialized).not.toContain(CURRENT_PASSWORD_SENTINEL);
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
  });
});
