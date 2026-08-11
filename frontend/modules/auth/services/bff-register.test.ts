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
import { performBffRegister } from './bff-register';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const TEST_USER_ID = FIXTURE_USER.id;

const PENDING_USER = {
  ...FIXTURE_USER,
  status: 'pending_verification' as const,
  email_verified_at: null,
  terms_version: '2026-01',
  terms_accepted_at: '2026-08-11T12:00:00.000Z',
};

const VALID_BODY = {
  name: 'Helter Dias',
  email: 'user@example.com',
  password: 'Abcdefghij1!',
  password_confirmation: 'Abcdefghij1!',
  accept_terms: true,
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

function makeRegisterRequest(
  options: {
    body?: unknown;
    csrfSid?: string;
    origin?: string;
    csrfToken?: string;
    contentType?: string;
    cookie?: string;
  } = {},
): Request {
  const csrfSid = options.csrfSid ?? 'register-sid';
  const csrfToken = options.csrfToken ?? derivePreAuthCsrfToken(csrfSid);
  const body = options.body === undefined ? VALID_BODY : options.body;

  const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`, `${CSRF_SID_COOKIE}=${csrfSid}`];
  if (options.cookie) {
    cookieParts.push(options.cookie);
  }

  return new Request('https://app.localhost/api/bff/auth/register', {
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

function upstream201(overrides: Parameters<typeof buildUpstreamAuthPayload>[0] = {}) {
  return Response.json(
    buildUpstreamAuthPayload({
      token_kind: 'verification',
      user: PENDING_USER,
      ...overrides,
    }),
    { status: 201 },
  );
}

describe('performBffRegister', () => {
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

  it('happy path returns 201, verification session, and redirect_to /verify-email (RGR-01, RGR-02)', async () => {
    const fetchMock = vi.fn(async () => upstream201());

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }

    expect(result.success.redirectTo).toBe('/verify-email');
    expect(result.response.status).toBe(201);
    const body = await result.response.json();
    expect(body.data.user.status).toBe('pending_verification');
    expect(body.data.user.email_verified_at).toBeNull();
    expect(body.data.user.terms_version).toBe('2026-01');
    expect(body.data.redirect_to).toBe('/verify-email');
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');

    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);

    const sessionCookie = setCookies.find((value) => value.includes('__Host-fl_session='));
    const sessionId = sessionCookie?.split(';')[0]?.split('=')[1];
    expect(sessionId).toBeTruthy();
    const sessionResult = await import('./bff-session').then((mod) =>
      mod.getSession(`${config.cookieName}=${sessionId}`, {
        config,
        store,
        now: () => fixedNow,
      }),
    );
    expect(sessionResult.context?.kind).toBe('verification');
  });

  it('success JSON.stringify must not contain Bearer fixture or token fields (RGR-03, BFFUI-17)', async () => {
    const fetchMock = vi.fn(async () => upstream201());

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));
    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }

    const body = await result.response.json();
    const serialized = JSON.stringify(body);
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('token_kind');
    expect(serialized).not.toContain('token_type');
    expect(serialized).not.toContain('expires_at');
    expect(serialized).not.toContain('Bearer');
  });

  it('forwards only RegisterRequest fields upstream and strips extras (RGR-10)', async () => {
    const fetchMock = vi.fn(async () => upstream201());

    await performBffRegister(
      makeRegisterRequest({
        body: {
          ...VALID_BODY,
          terms_version: '2026-01',
          returnUrl: '/dashboard',
          extra: 'nope',
        },
      }),
      deps(fetchMock),
    );

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/register',
      expect.objectContaining({
        body: JSON.stringify({
          name: VALID_BODY.name,
          email: VALID_BODY.email,
          password: VALID_BODY.password,
          password_confirmation: VALID_BODY.password_confirmation,
          accept_terms: true,
        }),
      }),
    );
  });

  it('returns 403 and does not fetch upstream when Origin is invalid (RGR-12)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffRegister(
      makeRegisterRequest({ origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 and does not fetch upstream when CSRF is invalid (RGR-12)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffRegister(
      makeRegisterRequest({ csrfToken: 'invalid-token' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 for malformed JSON without upstream fetch (RGR-10)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    const request = new Request('https://app.localhost/api/bff/auth/register', {
      method: 'POST',
      headers: makeRegisterRequest().headers,
      body: '{ invalid',
    });

    const result = await performBffRegister(request, deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 for non-json content type without upstream fetch (RGR-10)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffRegister(
      makeRegisterRequest({ contentType: 'text/plain' }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 when accept_terms is not literal true without upstream fetch', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));

    const result = await performBffRegister(
      makeRegisterRequest({ body: { ...VALID_BODY, accept_terms: false } }),
      deps(fetchMock),
    );

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(400);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards upstream 403 REGISTRATION_NOT_ALLOWED without session cookie (RGR-04)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'REGISTRATION_NOT_ALLOWED', message: 'Registration not allowed.' },
        { status: 403 },
      ),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({
      code: 'REGISTRATION_NOT_ALLOWED',
      message: 'Registration not allowed.',
    });
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('forwards upstream 422 validation errors without session cookie (RGR-09)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        {
          code: 'VALIDATION_FAILED',
          message: 'Invalid.',
          errors: { password: ['weak'] },
        },
        { status: 422 },
      ),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toMatchObject({
      code: 'VALIDATION_FAILED',
      errors: { password: ['weak'] },
    });
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('forwards upstream 429 with Retry-After header without session cookie (RGR-11)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(429);
    expect(result.response.headers.get('Retry-After')).toBe('60');
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('returns generic pt-BR message for upstream 503 without session cookie (RGR-15)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Service unavailable' }, { status: 503 }),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(503);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('returns generic pt-BR message for upstream 500 (RGR-15)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
  });

  it('returns 504 when upstream fetch aborts (RGR-15)', async () => {
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(504);
    expect(await result.response.json()).toEqual({
      message: 'Não foi possível conectar ao serviço. Tente novamente.',
    });
  });

  it('returns 500 without cookie when upstream 201 has token_kind !== verification', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        buildUpstreamAuthPayload({ token_kind: 'session', user: PENDING_USER }),
        { status: 201 },
      ),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('returns 500 when upstream 201 lacks token (RGR-16)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ data: { user: PENDING_USER } }, { status: 201 }),
    );

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
  });

  it('returns 500 when upstream 201 returns invalid JSON', async () => {
    const fetchMock = vi.fn(async () => new Response('not-json', { status: 201 }));

    const result = await performBffRegister(makeRegisterRequest(), deps(fetchMock));

    expect(result.ok).toBe(false);
    expect(result.response.status).toBe(500);
  });

  it('destroys prior session before creating a new verification session (BFFUI-15)', async () => {
    const { createSession } = await import('./bff-session');
    const prior = await createSession(
      { bearer: FIXTURE_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    const fetchMock = vi.fn(async () => upstream201());

    const result = await performBffRegister(
      makeRegisterRequest({ cookie: `${config.cookieName}=${prior.sessionId}` }),
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

  it('returns 500 when createSession fails after upstream 201 (RGR-15)', async () => {
    const fetchMock = vi.fn(async () => upstream201());
    const failingStore = {
      ...store,
      set: vi.fn(async () => {
        throw new Error('Redis unavailable');
      }),
    } as unknown as FakeSessionStore;

    const result = await performBffRegister(makeRegisterRequest(), {
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

  it('forwards a second REGISTRATION_NOT_ALLOWED fixture identically (anti-enum duplicate)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'REGISTRATION_NOT_ALLOWED', message: 'Registration not allowed.' },
        { status: 403 },
      ),
    );

    const invite = await performBffRegister(
      makeRegisterRequest({ body: { ...VALID_BODY, email: 'unknown@example.com' } }),
      deps(fetchMock),
    );
    const duplicate = await performBffRegister(
      makeRegisterRequest({ body: { ...VALID_BODY, email: 'taken@example.com' } }),
      deps(fetchMock),
    );

    expect(await invite.response.json()).toEqual(await duplicate.response.json());
    expect(invite.response.status).toBe(403);
    expect(duplicate.response.status).toBe(403);
  });
});
