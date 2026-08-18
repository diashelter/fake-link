import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import {
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  deriveCsrfToken,
  derivePreAuthCsrfToken,
} from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import {
  FIXTURE_BEARER,
  FIXTURE_USER,
  buildUpstreamAuthPayload,
} from '@/modules/auth/lib/test/auth-fixtures';

import { performBffLogin } from './bff-login';
import { createSession, getSession } from './bff-session';
import { performBffVerifyEmail } from './bff-verify-email';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const EMAIL_TOKEN_SENTINEL = 'email-token-SENTINEL-do-not-leak';
const SUCCESS_MESSAGE = 'E-mail confirmado. Faça login para continuar.';

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

describe('performBffVerifyEmail (EV-01–04, EV-08–11, EV-18–19)', () => {
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
    return createSession(
      { bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id },
      {
        config,
        store,
        now: () => fixedNow,
      },
    );
  }

  function makeRequest(
    options: {
      sessionId?: string;
      body?: unknown;
      rawBody?: string;
      origin?: string;
      csrfToken?: string;
      contentType?: string;
      includeSessionCookie?: boolean;
    } = {},
  ): Request {
    const sessionId = options.sessionId ?? 'unused-session';
    const csrfToken = options.csrfToken ?? deriveCsrfToken(sessionId);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`];
    if (options.includeSessionCookie !== false && options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }

    const body =
      options.rawBody !== undefined
        ? options.rawBody
        : JSON.stringify(
            options.body === undefined ? { token: EMAIL_TOKEN_SENTINEL } : options.body,
          );

    return new Request('https://app.localhost/api/bff/auth/email/verify', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
        'Content-Type': options.contentType ?? 'application/json',
      },
      body,
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

  it('translates upstream 204 into 200 with redirect_to /login and success message (EV-01)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual({
      data: { redirect_to: '/login', message: SUCCESS_MESSAGE },
    });
  });

  it('destroys the BFF session so it is no longer resolvable after success (EV-02)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    await performBffVerifyEmail(makeRequest({ sessionId: created.sessionId }), deps(fetchMock));

    const after = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });
    expect(after.context).toBeNull();
  });

  it('expires the session cookie with Max-Age=0 on success (EV-02)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
  });

  it('sets Cache-Control private, no-store on success (EV-01)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('omits Bearer, auth token fields, and the submitted email-token from the success JSON (EV-03, EV-19)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );
    const serialized = JSON.stringify(await result.response.json());

    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain('token_kind');
    expect(serialized).not.toContain('token_type');
    expect(serialized).not.toContain('expires_at');
    expect(serialized).not.toContain(EMAIL_TOKEN_SENTINEL);
  });

  it('calls Laravel with the session Bearer and only the token field (EV-01)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    await performBffVerifyEmail(
      makeRequest({
        sessionId: created.sessionId,
        body: { token: EMAIL_TOKEN_SENTINEL, extra: 'drop-me' },
      }),
      deps(fetchMock),
    );

    expect(fetchMock).toHaveBeenCalledOnce();
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/email/verify',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ token: EMAIL_TOKEN_SENTINEL }),
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
          'Content-Type': 'application/json',
        }),
      }),
    );
  });

  it('forwards 403 INVALID_VERIFICATION_TOKEN without destroying the session (EV-08)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'INVALID_VERIFICATION_TOKEN', message: 'Invalid.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('forwards 403 EMAIL_ALREADY_VERIFIED without destroying the session (EV-09)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'EMAIL_ALREADY_VERIFIED', message: 'Already verified.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
  });

  it('forwards upstream 401 without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'UNAUTHENTICATED', message: 'Unauthenticated.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 401 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(401);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
    expect(sessionCookieHeader(result.response)).not.toMatch(/Max-Age=0/i);
  });

  it('forwards upstream 422 errors without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = {
      code: 'VALIDATION_FAILED',
      message: 'Invalid.',
      errors: { token: ['required'] },
    };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 422 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
  });

  it('forwards upstream 429 and Retry-After without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(429);
    expect(result.response.headers.get('Retry-After')).toBe('60');
    expect(del).not.toHaveBeenCalled();
  });

  it('returns generic pt-BR for upstream 500 without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expect(del).not.toHaveBeenCalled();
  });

  it('returns generic pt-BR for upstream 503 without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Service unavailable' }, { status: 503 }),
    );

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(503);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expect(del).not.toHaveBeenCalled();
  });

  it('returns 504 gateway message when upstream fetch aborts without destroying the session', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(504);
    expect(await result.response.json()).toEqual({
      message: 'Não foi possível conectar ao serviço. Tente novamente.',
    });
    expect(del).not.toHaveBeenCalled();
  });

  it('returns 400 for malformed JSON without calling Laravel (EV-18)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, rawBody: '{ invalid' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 for invalid Content-Type without calling Laravel (EV-18)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({
        sessionId: created.sessionId,
        contentType: 'text/plain',
        rawBody: JSON.stringify({ token: EMAIL_TOKEN_SENTINEL }),
      }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards surrounding whitespace in the token to Laravel without trimming (EV-18)', async () => {
    const created = await createKindSession('verification');
    const paddedToken = '  opaque-token  ';
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, body: { token: paddedToken } }),
      deps(fetchMock),
    );

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/email/verify',
      expect.objectContaining({
        body: JSON.stringify({ token: paddedToken }),
      }),
    );
  });

  it('returns 400 for missing or empty token without calling Laravel (EV-18)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const missing = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, body: {} }),
      deps(fetchMock),
    );
    expect(missing.response.status).toBe(400);
    expect(await missing.response.json()).toEqual({ message: 'Requisição inválida.' });

    const empty = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, body: { token: '' } }),
      deps(fetchMock),
    );
    expect(empty.response.status).toBe(400);
    expect(await empty.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 without calling Laravel when session kind is session (EV-04)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 without calling Laravel when Origin or CSRF fails (EV-11)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const originFail = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, origin: 'https://evil.com' }),
      deps(fetchMock),
    );
    expect(originFail.response.status).toBe(403);
    expect(await originFail.response.json()).toEqual({ message: 'Forbidden.' });

    const csrfFail = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId, csrfToken: 'invalid-csrf' }),
      deps(fetchMock),
    );
    expect(csrfFail.response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('still returns 200 and clears the cookie when destroySession throws', async () => {
    const created = await createKindSession('verification');
    vi.spyOn(store, 'del').mockRejectedValueOnce(new Error('Redis down'));
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual({
      data: { redirect_to: '/login', message: SUCCESS_MESSAGE },
    });
    expect(sessionCookieHeader(result.response)).toMatch(/Max-Age=0/i);
  });

  it('forwards 403 ACCOUNT_SUSPENDED without destroying the session (EV-10)', async () => {
    const created = await createKindSession('verification');
    const del = vi.spyOn(store, 'del');
    const payload = { code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffVerifyEmail(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual(payload);
    expect(del).not.toHaveBeenCalled();
  });

  it('after successful verify, a subsequent login issues a session-kind cookie (BFFUI-52)', async () => {
    const created = await createKindSession('verification');
    const verifyFetch = vi.fn(async () => new Response(null, { status: 204 }));

    await performBffVerifyEmail(makeRequest({ sessionId: created.sessionId }), deps(verifyFetch));

    const csrfSid = 'post-verify-login-sid';
    const csrfToken = derivePreAuthCsrfToken(csrfSid);
    const loginRequest = new Request('https://app.localhost/api/bff/auth/login', {
      method: 'POST',
      headers: {
        Origin: 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: `${CSRF_TOKEN_COOKIE}=${csrfToken}; ${CSRF_SID_COOKIE}=${csrfSid}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email: 'user@example.com', password: 'secret' }),
    });

    const loginFetch = vi.fn(async () =>
      Response.json(buildUpstreamAuthPayload({ token_kind: 'session' }), { status: 200 }),
    );
    const loginResult = await performBffLogin(loginRequest, deps(loginFetch));

    expect(loginResult.ok).toBe(true);
    if (!loginResult.ok) {
      return;
    }

    expect(loginResult.success.redirectTo).toBe('/');
    const cookieHeader = sessionCookieHeader(loginResult.response);
    const sessionIdMatch = cookieHeader.match(/__Host-fl_session=([^;]+)/);
    expect(sessionIdMatch?.[1]).toBeTruthy();

    const after = await getSession(`${config.cookieName}=${sessionIdMatch?.[1] ?? ''}`, {
      config,
      store,
      now: () => fixedNow,
    });
    expect(after.context?.kind).toBe('session');
  });
});
