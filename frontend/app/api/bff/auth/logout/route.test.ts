import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';
import { createSession } from '@/modules/auth/services/bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const LOGOUT_SUCCESS_MESSAGE = 'Você saiu da conta.';

const logoutHarness = vi.hoisted(() => {
  type Perform = typeof import('@/modules/auth/services/bff-logout').performBffLogout;
  return {
    mock: vi.fn<Perform>(),
    actual: null as Perform | null,
  };
});

vi.mock('@/modules/auth/services/bff-logout', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/modules/auth/services/bff-logout')>();
  logoutHarness.actual = actual.performBffLogout;
  return {
    ...actual,
    performBffLogout: (
      ...args: Parameters<typeof actual.performBffLogout>
    ): ReturnType<typeof actual.performBffLogout> => logoutHarness.mock(...args),
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

describe('POST /api/bff/auth/logout', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    logoutHarness.mock.mockReset();
    logoutHarness.mock.mockImplementation((request, deps) => {
      const actual = logoutHarness.actual;
      if (!actual) {
        throw new Error('performBffLogout actual implementation was not loaded');
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

  function makePostRequest(options: { sessionId: string; origin?: string }): Request {
    const csrfToken = deriveCsrfToken(options.sessionId);

    return new Request('https://app.localhost/api/bff/auth/logout', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: `${CSRF_TOKEN_COOKIE}=${csrfToken}; ${config.cookieName}=${options.sessionId}`,
      },
    });
  }

  it('delegates POST to performBffLogout and returns 200 with expired session cookie (SH-01)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ sessionId: created.sessionId }));
    const body = await response.json();
    const serialized = JSON.stringify(body);

    expect(logoutHarness.mock).toHaveBeenCalledOnce();
    expect(response.status).toBe(200);
    expect(body).toEqual({
      data: { redirect_to: '/login', message: LOGOUT_SUCCESS_MESSAGE },
    });
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
    expect(setCookies.some((value) => /Max-Age=0/i.test(value))).toBe(true);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
  });
});
