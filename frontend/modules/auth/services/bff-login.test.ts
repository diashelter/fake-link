import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import {
  derivePreAuthCsrfToken,
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
} from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';

import {
  buildUpstreamAuthPayload,
  FIXTURE_BEARER,
  FIXTURE_USER,
} from '@/modules/auth/lib/test/auth-fixtures';
import { performBffLogin } from './bff-login';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const TEST_USER_ID = FIXTURE_USER.id;

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

function makeLoginRequest(
  options: {
    body?: unknown;
    returnUrl?: string;
    csrfSid?: string;
    origin?: string;
    csrfToken?: string;
    contentType?: string;
    cookie?: string;
  } = {},
): Request {
  const csrfSid = options.csrfSid ?? 'login-sid';
  const csrfToken = options.csrfToken ?? derivePreAuthCsrfToken(csrfSid);
  const returnUrlQuery = options.returnUrl
    ? `?returnUrl=${encodeURIComponent(options.returnUrl)}`
    : '';
  const body =
    options.body === undefined ? { email: 'user@example.com', password: 'secret' } : options.body;

  const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`, `${CSRF_SID_COOKIE}=${csrfSid}`];
  if (options.cookie) {
    cookieParts.push(options.cookie);
  }

  return new Request(`https://app.localhost/api/bff/auth/login${returnUrlQuery}`, {
    method: 'POST',
    headers: {
      Origin: options.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: cookieParts.join('; '),
      'Content-Type': options.contentType ?? 'application/json',
    },
    body: body === null ? undefined : JSON.stringify(body),
  });
}

describe('performBffLogin', () => {
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
    vi.unstubAllGlobals();
  });

  function deps(fetchImpl: typeof fetch) {
    return { config, store, now: () => fixedNow, fetchImpl };
  }

  it('happy path active user returns sanitized redirect_to and session cookie', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(buildUpstreamAuthPayload({ token_kind: 'session' }), { status: 200 }),
    );

    const result = await performBffLogin(
      makeLoginRequest({ returnUrl: '/dashboard' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }

    expect(result.success.redirectTo).toBe('/dashboard');
    expect(result.response.status).toBe(200);
    const body = await result.response.json();
    expect(body.data.user.email).toBe(FIXTURE_USER.email);
    expect(body.data.redirect_to).toBe('/dashboard');
    expect(JSON.stringify(body)).not.toContain(FIXTURE_BEARER);
    expect(JSON.stringify(body)).not.toContain('token_kind');
    expect(JSON.stringify(body)).not.toContain('Bearer');

    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/login',
      expect.objectContaining({
        body: JSON.stringify({ email: 'user@example.com', password: 'secret' }),
      }),
    );
  });

  it('verification user always redirects to /verify-email', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(buildUpstreamAuthPayload({ token_kind: 'verification' }), { status: 200 }),
    );

    const result = await performBffLogin(
      makeLoginRequest({ returnUrl: '/dashboard' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }
    expect(result.success.redirectTo).toBe('/verify-email');
    const body = await result.response.json();
    expect(body.data.redirect_to).toBe('/verify-email');
  });

  it('sanitizes malicious returnUrl to /', async () => {
    const fetchMock = vi.fn(async () => Response.json(buildUpstreamAuthPayload(), { status: 200 }));

    const result = await performBffLogin(
      makeLoginRequest({ returnUrl: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }
    expect(result.success.redirectTo).toBe('/');
  });

  it('returns 403 and does not fetch upstream when Origin is invalid', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffLogin(
      makeLoginRequest({ origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 and does not fetch upstream when CSRF is invalid', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffLogin(
      makeLoginRequest({ csrfToken: 'invalid-token' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 for malformed JSON without upstream fetch', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    const request = new Request('https://app.localhost/api/bff/auth/login', {
      method: 'POST',
      headers: makeLoginRequest().headers,
      body: '{ invalid',
    });

    const result = await performBffLogin(request, deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 for non-json content type without upstream fetch', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffLogin(
      makeLoginRequest({ contentType: 'text/plain' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards upstream 401 without session cookie', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' },
        { status: 401 },
      ),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(401);
    expect(await result.response.json()).toEqual({
      code: 'INVALID_CREDENTIALS',
      message: 'Invalid credentials.',
    });
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('forwards upstream 403 account status without session cookie', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' }, { status: 403 }),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toMatchObject({ code: 'ACCOUNT_SUSPENDED' });
  });

  it('forwards upstream 422 validation errors', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'VALIDATION_FAILED', message: 'Invalid.', errors: { email: ['bad'] } },
        { status: 422 },
      ),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toMatchObject({ code: 'VALIDATION_FAILED' });
  });

  it('forwards upstream 429 with Retry-After header', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(429);
    expect(result.response.headers.get('Retry-After')).toBe('60');
  });

  it('returns generic pt-BR message for upstream 500', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
  });

  it('returns 504 when upstream fetch aborts', async () => {
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(504);
    expect(await result.response.json()).toEqual({
      message: 'Não foi possível conectar ao serviço. Tente novamente.',
    });
  });

  it('returns 500 when upstream 200 lacks token', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ data: { user: FIXTURE_USER } }, { status: 200 }),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
  });

  it('returns 500 when upstream 200 has unknown token_kind', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(buildUpstreamAuthPayload({ token_kind: 'unknown' as 'session' }), {
        status: 200,
      }),
    );

    const result = await performBffLogin(makeLoginRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
  });

  it('destroys prior session before creating a new one', async () => {
    const { createSession } = await import('./bff-session');
    const prior = await createSession(
      { bearer: FIXTURE_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    const fetchMock = vi.fn(async () => Response.json(buildUpstreamAuthPayload(), { status: 200 }));

    const result = await performBffLogin(
      makeLoginRequest({ cookie: `${config.cookieName}=${prior.sessionId}` }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(true);
    const priorResult = await import('./bff-session').then((mod) =>
      mod.getSession(`${config.cookieName}=${prior.sessionId}`, {
        config,
        store,
        now: () => fixedNow,
      }),
    );
    expect(priorResult.context).toBeNull();
  });

  it('returns 500 when createSession fails after upstream 200', async () => {
    const fetchMock = vi.fn(async () => Response.json(buildUpstreamAuthPayload(), { status: 200 }));
    const failingStore = {
      ...store,
      set: vi.fn(async () => {
        throw new Error('Redis unavailable');
      }),
    } as unknown as FakeSessionStore;

    const result = await performBffLogin(makeLoginRequest(), {
      config,
      store: failingStore,
      now: () => fixedNow,
      fetchImpl: fetchMock,
    });

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });
});
