import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { performBffMeGet, performBffMePatch } from './bff-me';
import { createSession } from './bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const USER_ENVELOPE = { data: FIXTURE_USER };
const VERIFICATION_USER_ENVELOPE = {
  data: {
    ...FIXTURE_USER,
    status: 'pending_verification' as const,
    email_verified_at: null,
  },
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

describe('performBffMeGet / performBffMePatch (SH-10–13, SH-21)', () => {
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

  function makeGetRequest(
    options: {
      sessionId?: string;
      includeCsrf?: boolean;
      includeOrigin?: boolean;
    } = {},
  ): Request {
    const headers = new Headers();
    const cookieParts: string[] = [];
    if (options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }
    if (options.includeCsrf && options.sessionId) {
      const csrfToken = deriveCsrfToken(options.sessionId);
      headers.set('X-CSRF-Token', csrfToken);
      cookieParts.push(`${CSRF_TOKEN_COOKIE}=${csrfToken}`);
    }
    if (options.includeOrigin) {
      headers.set('Origin', 'https://app.localhost');
    }
    if (cookieParts.length > 0) {
      headers.set('cookie', cookieParts.join('; '));
    }

    return new Request('https://app.localhost/api/bff/auth/me', {
      method: 'GET',
      headers,
    });
  }

  function makePatchRequest(
    options: {
      sessionId?: string;
      body?: unknown;
      origin?: string;
      csrfToken?: string;
      contentType?: string | null;
      rawBody?: string;
    } = {},
  ): Request {
    const sessionId = options.sessionId ?? 'unused-session';
    const csrfToken = options.csrfToken ?? deriveCsrfToken(sessionId);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`];
    if (options.sessionId) {
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

    return new Request('https://app.localhost/api/bff/auth/me', {
      method: 'PATCH',
      headers,
      body: options.rawBody ?? JSON.stringify(options.body ?? { name: 'Novo Nome' }),
    });
  }

  it('passes through GET 200 User envelope for session kind without CSRF or Origin (SH-10)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const result = await performBffMeGet(
      makeGetRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    const body = await result.response.json();
    expect(body).toEqual(USER_ENVELOPE);
    expect(body.data).toEqual(
      expect.objectContaining({
        id: FIXTURE_USER.id,
        name: FIXTURE_USER.name,
        email: FIXTURE_USER.email,
        status: FIXTURE_USER.status,
        email_verified_at: FIXTURE_USER.email_verified_at,
        terms_version: FIXTURE_USER.terms_version,
        terms_accepted_at: FIXTURE_USER.terms_accepted_at,
        created_at: FIXTURE_USER.created_at,
        updated_at: FIXTURE_USER.updated_at,
      }),
    );
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/me',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
        }),
      }),
    );
  });

  it('passes through GET 200 for verification kind with pending_verification status (SH-10)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => Response.json(VERIFICATION_USER_ENVELOPE, { status: 200 }));

    const result = await performBffMeGet(
      makeGetRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(VERIFICATION_USER_ENVELOPE);
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  it('returns 403 without Laravel when GET session is a miss (SH-13)', async () => {
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const result = await performBffMeGet(makeGetRequest(), deps(fetchMock));

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('patches name and passes through 200 UserResponse (SH-11)', async () => {
    const created = await createKindSession('session');
    const updated = { data: { ...FIXTURE_USER, name: 'Novo Nome' } };
    const fetchMock = vi.fn(async () => Response.json(updated, { status: 200 }));

    const result = await performBffMePatch(
      makePatchRequest({ sessionId: created.sessionId, body: { name: '  Novo Nome  ' } }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(200);
    expect(await result.response.json()).toEqual(updated);
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    const firstCall = fetchMock.mock.calls[0] as unknown as [unknown, RequestInit] | undefined;
    expect(JSON.parse(String(firstCall?.[1].body))).toEqual({ name: 'Novo Nome' });
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/me',
      expect.objectContaining({
        method: 'PATCH',
        headers: expect.objectContaining({
          Authorization: `Bearer ${FIXTURE_BEARER}`,
          'Content-Type': 'application/json',
        }),
      }),
    );
  });

  it('returns 400 without fetch when PATCH includes email or extra fields (SH-12)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const emailResult = await performBffMePatch(
      makePatchRequest({
        sessionId: created.sessionId,
        body: { name: 'Ana', email: 'other@example.com' },
      }),
      deps(fetchMock),
    );
    const extraResult = await performBffMePatch(
      makePatchRequest({
        sessionId: created.sessionId,
        body: { name: 'Ana', extra: 'nope' },
      }),
      deps(fetchMock),
    );

    expect(emailResult.response.status).toBe(400);
    expect(await emailResult.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(extraResult.response.status).toBe(400);
    expect(await extraResult.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 400 without fetch when PATCH name is empty after trim or longer than 120 (SH-12)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const emptyResult = await performBffMePatch(
      makePatchRequest({ sessionId: created.sessionId, body: { name: '   ' } }),
      deps(fetchMock),
    );
    const longResult = await performBffMePatch(
      makePatchRequest({
        sessionId: created.sessionId,
        body: { name: 'A'.repeat(121) },
      }),
      deps(fetchMock),
    );

    expect(emptyResult.response.status).toBe(400);
    expect(await emptyResult.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(longResult.response.status).toBe(400);
    expect(await longResult.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 without fetch when PATCH session kind is verification (SH-12)', async () => {
    const created = await createKindSession('verification');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const result = await performBffMePatch(
      makePatchRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 without fetch when PATCH has no session (SH-13)', async () => {
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const result = await performBffMePatch(makePatchRequest(), deps(fetchMock));

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('omits Bearer sentinel from GET JSON (SH-21)', async () => {
    const created = await createKindSession('session');
    const fetchMock = vi.fn(async () => Response.json(USER_ENVELOPE, { status: 200 }));

    const result = await performBffMeGet(
      makeGetRequest({ sessionId: created.sessionId }),
      deps(fetchMock),
    );
    const serialized = JSON.stringify(await result.response.json());

    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
  });
});
