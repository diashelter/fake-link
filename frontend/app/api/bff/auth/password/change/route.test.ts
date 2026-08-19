import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';
import { createSession } from '@/modules/auth/services/bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const CURRENT_PASSWORD_SENTINEL = 'old-secret-SENTINEL';
const PASSWORD_SENTINEL = 'Abcdefghij1!';
const CHANGE_SUCCESS_MESSAGE = 'Senha alterada. Faça login para continuar.';

const VALID_BODY = {
  current_password: CURRENT_PASSWORD_SENTINEL,
  password: PASSWORD_SENTINEL,
  password_confirmation: PASSWORD_SENTINEL,
};

const changeHarness = vi.hoisted(() => {
  type Perform =
    typeof import('@/modules/auth/services/bff-password-change').performBffPasswordChange;
  return {
    mock: vi.fn<Perform>(),
    actual: null as Perform | null,
  };
});

vi.mock('@/modules/auth/services/bff-password-change', async (importOriginal) => {
  const actual =
    await importOriginal<typeof import('@/modules/auth/services/bff-password-change')>();
  changeHarness.actual = actual.performBffPasswordChange;
  return {
    ...actual,
    performBffPasswordChange: (
      ...args: Parameters<typeof actual.performBffPasswordChange>
    ): ReturnType<typeof actual.performBffPasswordChange> => changeHarness.mock(...args),
  };
});

import { POST } from './route';

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

describe('POST /api/bff/auth/password/change', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    changeHarness.mock.mockReset();
    changeHarness.mock.mockImplementation((request, deps) => {
      const actual = changeHarness.actual;
      if (!actual) {
        throw new Error('performBffPasswordChange actual implementation was not loaded');
      }
      return actual(request, {
        config,
        store,
        now: () => fixedNow,
        ...deps,
      });
    });
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  async function createKindSession(kind: 'session' | 'verification') {
    return createSession(
      { bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id },
      { config, store, now: () => fixedNow },
    );
  }

  function makePostRequest(options: {
    sessionId: string;
    csrfToken?: string;
    origin?: string;
  }): Request {
    const csrfToken = options.csrfToken ?? deriveCsrfToken(options.sessionId);

    return new Request('https://app.localhost/api/bff/auth/password/change', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: `${CSRF_TOKEN_COOKIE}=${csrfToken}; ${config.cookieName}=${options.sessionId}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(VALID_BODY),
    });
  }

  it('returns 200 with expired session cookie and sanitized body on happy path (PW-12, BFFUI-62)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ sessionId: created.sessionId }));
    const body = await response.json();
    const serialized = JSON.stringify(body);

    expect(response.status).toBe(200);
    expect(body).toEqual({
      data: { redirect_to: '/login', message: CHANGE_SUCCESS_MESSAGE },
    });
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
    expect(setCookies.some((value) => /Max-Age=0/i.test(value))).toBe(true);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain(CURRENT_PASSWORD_SENTINEL);
    expect(serialized).not.toContain(PASSWORD_SENTINEL);
  });

  it('returns 403 when session kind is verification without calling upstream (PW-13)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ sessionId: created.sessionId }));

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on CSRF failure without calling upstream (PW-19)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(
      makePostRequest({ sessionId: created.sessionId, csrfToken: 'bad-token' }),
    );

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on invalid Origin without calling upstream (PW-19)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(
      makePostRequest({ sessionId: created.sessionId, origin: 'https://evil.com' }),
    );

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
