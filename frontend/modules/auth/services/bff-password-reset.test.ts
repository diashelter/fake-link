import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import {
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  derivePreAuthCsrfToken,
} from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { performBffPasswordReset } from './bff-password-reset';
import * as bffSession from './bff-session';
import { createSession } from './bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const TOKEN_SENTINEL = 'reset-token-SENTINEL-do-not-leak';
const PASSWORD_SENTINEL = 'Abcdefghij1!';
const RESET_SUCCESS_MESSAGE = 'Senha redefinida. Faça login para continuar.';

const VALID_BODY = {
  email: 'user@example.com',
  token: TOKEN_SENTINEL,
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

describe('performBffPasswordReset (PW-06–08, PW-19–22)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');
  let getSessionSpy: ReturnType<typeof vi.spyOn>;
  let destroySessionSpy: ReturnType<typeof vi.spyOn>;
  let clearSessionCookieSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    getSessionSpy = vi.spyOn(bffSession, 'getSession');
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
    return createSession({ bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id }, {
      config,
      store,
      now: () => fixedNow,
    });
  }

  function makeRequest(
    options: {
      body?: unknown;
      sessionId?: string;
      origin?: string;
      csrfToken?: string;
      contentType?: string;
      rawBody?: string;
    } = {},
  ): Request {
    const csrfSid = 'reset-sid';
    const csrfToken = options.csrfToken ?? derivePreAuthCsrfToken(csrfSid);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`, `${CSRF_SID_COOKIE}=${csrfSid}`];
    if (options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }

    return new Request('https://app.localhost/api/bff/auth/password/reset', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
        'Content-Type': options.contentType ?? 'application/json',
      },
      body: options.rawBody ?? JSON.stringify(options.body ?? VALID_BODY),
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

  it('translates upstream 204 into 200, destroys session, and clears cookie (PW-06, PW-07)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordReset(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual({
      data: { redirect_to: '/login', message: RESET_SUCCESS_MESSAGE },
    });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(destroySessionSpy).toHaveBeenCalledWith(created.sessionId, expect.anything());
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/password/reset',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify(VALID_BODY),
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    expect(JSON.stringify(fetchMock.mock.calls[0]?.[1])).not.toContain('Authorization');
    expect(JSON.stringify(fetchMock.mock.calls[0]?.[1])).not.toContain(FIXTURE_BEARER);
  });

  it('forwards 422 token errors without destroy or cookie clear (PW-08)', async () => {
    const created = await createKindSession('session');
    const payload = {
      code: 'VALIDATION_FAILED',
      errors: { token: [{ code: 'INVALID_TOKEN', message: 'The reset token is invalid.' }] },
    };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 422 }));

    const result = await performBffPasswordReset(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toEqual(payload);
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
    expect(getSessionSpy).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('forwards 422 PASSWORD_REUSED without destroy (PW-08)', async () => {
    const created = await createKindSession('session');
    const payload = {
      code: 'VALIDATION_FAILED',
      errors: {
        password: [{ code: 'PASSWORD_REUSED', message: 'The new password must be different.' }],
      },
    };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 422 }));

    const result = await performBffPasswordReset(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toEqual(payload);
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('omits password, token, and Bearer sentinels from success JSON (PW-22)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordReset(makeRequest(), deps(fetchMock));
    const serialized = JSON.stringify(await result.response.json());

    expect(serialized).not.toContain(PASSWORD_SENTINEL);
    expect(serialized).not.toContain(TOKEN_SENTINEL);
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
  });

  it('still returns 200 and clears the cookie when destroySession throws (PW-07)', async () => {
    const created = await createKindSession('verification');
    destroySessionSpy.mockRejectedValueOnce(new Error('Redis down'));
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordReset(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual({
      data: { redirect_to: '/login', message: RESET_SUCCESS_MESSAGE },
    });
    expect(clearSessionCookieSpy).toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
  });

  it('does not trim the token before upstream fetch (PW-18)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    const token = ' abc ';

    await performBffPasswordReset(makeRequest({ body: { ...VALID_BODY, token } }), deps(fetchMock));

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/password/reset',
      expect.objectContaining({
        body: JSON.stringify({ ...VALID_BODY, token }),
      }),
    );
  });

  it('drops extra body fields before calling Laravel (PW-18)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    await performBffPasswordReset(
      makeRequest({ body: { ...VALID_BODY, extra: 'drop-me' } }),
      deps(fetchMock),
    );

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/password/reset',
      expect.objectContaining({ body: JSON.stringify(VALID_BODY) }),
    );
  });

  it('returns 400 for malformed JSON without upstream fetch (PW-18)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordReset(
      makeRequest({ rawBody: '{ invalid' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
  });

  it('returns 403 and does not fetch when Origin is invalid (PW-19)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordReset(
      makeRequest({ origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(getSessionSpy).not.toHaveBeenCalled();
  });
});
