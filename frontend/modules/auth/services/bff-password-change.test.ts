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
      body?: unknown;
      origin?: string;
      csrfToken?: string;
      includeSessionCookie?: boolean;
      contentType?: string | null;
      rawBody?: string;
    } = {},
  ): Request {
    const sessionId = options.sessionId ?? 'unused-session';
    const csrfToken = options.csrfToken ?? deriveCsrfToken(sessionId);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`];
    if (options.includeSessionCookie !== false && options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }

    const headers = new Headers({
      Origin: options.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: cookieParts.join('; '),
    });
    if (options.contentType !== null) {
      headers.set('Content-Type', options.contentType ?? 'application/json');
    }

    return new Request('https://app.localhost/api/bff/auth/password/change', {
      method: 'POST',
      headers,
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

  it('forwards 403 ACCOUNT_SUSPENDED without destroying the session (PW-21)', async () => {
    const created = await createKindSession('session');
    const payload = { code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toMatchObject({ code: 'ACCOUNT_SUSPENDED' });
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('forwards 403 ACCOUNT_PENDING_DELETION without destroying the session (PW-21)', async () => {
    const created = await createKindSession('session');
    const payload = { code: 'ACCOUNT_PENDING_DELETION', message: 'Pending deletion.' };
    const fetchMock = vi.fn(async () => Response.json(payload, { status: 403 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toMatchObject({ code: 'ACCOUNT_PENDING_DELETION' });
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
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

  it('forwards upstream 429 with Retry-After without destroying the session (PW-20)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(429);
    expect(result.response.headers.get('Retry-After')).toBe('60');
    expect(await result.response.json()).toMatchObject({ code: 'RATE_LIMIT_EXCEEDED' });
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('returns 504 generic pt-BR when upstream fetch aborts (PW-21)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(504);
    expect(await result.response.json()).toEqual({
      message: 'Não foi possível conectar ao serviço. Tente novamente.',
    });
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('returns generic pt-BR message for upstream 500 without leaking Laravel body (PW-21)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(500);
    const body = await result.response.json();
    expect(body).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expect(JSON.stringify(body)).not.toContain('Internal stack trace');
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('returns generic pt-BR message for upstream 503 without destroying the session (PW-21)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Service unavailable' }, { status: 503 }),
    );

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(503);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('forwards only schema fields and drops extra body keys (PW-18)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn<typeof fetch>(async () => new Response(null, { status: 204 }));

    await performBffPasswordChange(
      makeRequest({
        sessionId: created.sessionId,
        body: { ...VALID_BODY, extra: 'drop-me', token: 'also-drop' },
      }),
      deps(fetchMock),
    );

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect(JSON.parse(String(init.body))).toEqual({
      current_password: CURRENT_PASSWORD_SENTINEL,
      password: PASSWORD_SENTINEL,
      password_confirmation: PASSWORD_SENTINEL,
    });
  });

  it('returns 400 for malformed JSON without upstream fetch (PW-18)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId, rawBody: '{ invalid' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
    expect(clearSessionCookieSpy).not.toHaveBeenCalled();
  });

  it('returns 400 for missing Content-Type without upstream fetch (PW-18)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));

    const result = await performBffPasswordChange(
      makeRequest({ sessionId: created.sessionId, contentType: null }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
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
