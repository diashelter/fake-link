import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';
import { createSession } from '@/modules/auth/services/bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const USER_ENVELOPE = { data: FIXTURE_USER };

const meHarness = vi.hoisted(() => {
  type GetPerform = typeof import('@/modules/auth/services/bff-me').performBffMeGet;
  type PatchPerform = typeof import('@/modules/auth/services/bff-me').performBffMePatch;
  return {
    getMock: vi.fn<GetPerform>(),
    patchMock: vi.fn<PatchPerform>(),
    actualGet: null as GetPerform | null,
    actualPatch: null as PatchPerform | null,
  };
});

vi.mock('@/modules/auth/services/bff-me', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/modules/auth/services/bff-me')>();
  meHarness.actualGet = actual.performBffMeGet;
  meHarness.actualPatch = actual.performBffMePatch;
  return {
    ...actual,
    performBffMeGet: (
      ...args: Parameters<typeof actual.performBffMeGet>
    ): ReturnType<typeof actual.performBffMeGet> => meHarness.getMock(...args),
    performBffMePatch: (
      ...args: Parameters<typeof actual.performBffMePatch>
    ): ReturnType<typeof actual.performBffMePatch> => meHarness.patchMock(...args),
  };
});

import { GET, PATCH, POST } from './route';

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

describe('/api/bff/auth/me', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    meHarness.getMock.mockReset();
    meHarness.patchMock.mockReset();
    meHarness.getMock.mockImplementation((request, deps) => {
      const actual = meHarness.actualGet;
      if (!actual) {
        throw new Error('performBffMeGet actual implementation was not loaded');
      }
      return actual(request, {
        config,
        store,
        now: () => fixedNow,
        ...deps,
      });
    });
    meHarness.patchMock.mockImplementation((request, deps) => {
      const actual = meHarness.actualPatch;
      if (!actual) {
        throw new Error('performBffMePatch actual implementation was not loaded');
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

  function makeGetRequest(sessionId: string): Request {
    return new Request('https://app.localhost/api/bff/auth/me', {
      method: 'GET',
      headers: {
        cookie: `${config.cookieName}=${sessionId}`,
      },
    });
  }

  function makePatchRequest(sessionId: string, body: unknown = { name: 'Novo Nome' }): Request {
    const csrfToken = deriveCsrfToken(sessionId);
    return new Request('https://app.localhost/api/bff/auth/me', {
      method: 'PATCH',
      headers: {
        Origin: 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: `${CSRF_TOKEN_COOKIE}=${csrfToken}; ${config.cookieName}=${sessionId}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body),
    });
  }

  it('delegates GET to performBffMeGet and passes through User envelope (SH-10)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await GET(makeGetRequest(created.sessionId));
    const body = await response.json();

    expect(meHarness.getMock).toHaveBeenCalledOnce();
    expect(meHarness.patchMock).not.toHaveBeenCalled();
    expect(response.status).toBe(200);
    expect(body).toEqual(USER_ENVELOPE);
    expect(body.data).toEqual(
      expect.objectContaining({
        id: FIXTURE_USER.id,
        name: FIXTURE_USER.name,
        email: FIXTURE_USER.email,
        status: FIXTURE_USER.status,
      }),
    );
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(JSON.stringify(body)).not.toContain(FIXTURE_BEARER);
  });

  it('delegates PATCH to performBffMePatch and passes through User envelope (SH-11)', async () => {
    const created = await createKindSession('session');
    const updated = { data: { ...FIXTURE_USER, name: 'Novo Nome' } };
    const fetchMock = vi.fn(async () => Response.json(updated, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await PATCH(makePatchRequest(created.sessionId));
    const body = await response.json();

    expect(meHarness.patchMock).toHaveBeenCalledOnce();
    expect(meHarness.getMock).not.toHaveBeenCalled();
    expect(response.status).toBe(200);
    expect(body).toEqual(updated);
    expect(JSON.stringify(body)).not.toContain(FIXTURE_BEARER);
  });

  it('returns 405 for POST', async () => {
    const response = await POST();

    expect(response.status).toBe(405);
    expect(await response.json()).toEqual({ message: 'Method Not Allowed.' });
    expect(meHarness.getMock).not.toHaveBeenCalled();
    expect(meHarness.patchMock).not.toHaveBeenCalled();
  });
});
