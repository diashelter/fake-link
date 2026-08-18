import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { CSRF_TOKEN_COOKIE, deriveCsrfToken } from '@/modules/auth/bff/csrf';
import type { AllowlistEntry } from '@/modules/auth/bff/types';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import { createSession } from './bff-session';
import { loadVerificationMutationContext } from './bff-email-verification-shared';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

const LOCAL_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/email/verify',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/email/verify',
  requireSession: true,
  requireCsrf: true,
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

describe('loadVerificationMutationContext (EV-04, EV-11)', () => {
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

  function deps() {
    return { config, store, now: () => fixedNow };
  }

  async function createKindSession(kind: 'session' | 'verification') {
    return createSession(
      { bearer: FIXTURE_BEARER, kind, userId: FIXTURE_USER.id },
      deps(),
    );
  }

  function makeRequest(options: {
    sessionId?: string;
    origin?: string;
    csrfToken?: string;
    includeSessionCookie?: boolean;
  } = {}): Request {
    const sessionId = options.sessionId ?? 'unused-session';
    const csrfToken = options.csrfToken ?? deriveCsrfToken(sessionId);
    const cookieParts = [`${CSRF_TOKEN_COOKIE}=${csrfToken}`];
    if (options.includeSessionCookie !== false && options.sessionId) {
      cookieParts.push(`${config.cookieName}=${options.sessionId}`);
    }

    return new Request('https://app.localhost/api/bff/auth/email/verify', {
      method: 'POST',
      headers: {
        Origin: options.origin ?? 'https://app.localhost',
        'X-CSRF-Token': csrfToken,
        cookie: cookieParts.join('; '),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ token: 'opaque-token' }),
    });
  }

  it('returns 403 Forbidden without loading session when Origin is invalid (EV-11)', async () => {
    const created = await createKindSession('verification');
    const storeGet = vi.spyOn(store, 'get');

    const result = await loadVerificationMutationContext(
      makeRequest({ sessionId: created.sessionId, origin: 'https://evil.com' }),
      LOCAL_ENTRY,
      deps(),
    );

    expect(result.ok).toBe(false);
    if (result.ok) {
      return;
    }
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(storeGet).not.toHaveBeenCalled();
  });

  it('returns 403 Forbidden when session cookie is absent (EV-11)', async () => {
    const result = await loadVerificationMutationContext(
      makeRequest({ includeSessionCookie: false, csrfToken: 'not-a-session-csrf' }),
      LOCAL_ENTRY,
      deps(),
    );

    expect(result.ok).toBe(false);
    if (result.ok) {
      return;
    }
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
  });

  it('returns 403 Forbidden without a kind re-read when CSRF is invalid (EV-11)', async () => {
    const created = await createKindSession('verification');
    const storeGet = vi.spyOn(store, 'get');

    const result = await loadVerificationMutationContext(
      makeRequest({ sessionId: created.sessionId, csrfToken: 'invalid-csrf' }),
      LOCAL_ENTRY,
      deps(),
    );

    expect(result.ok).toBe(false);
    if (result.ok) {
      return;
    }
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(storeGet).toHaveBeenCalledOnce();
  });

  it('returns 403 Forbidden when session kind is session (EV-04)', async () => {
    const created = await createKindSession('session');

    const result = await loadVerificationMutationContext(
      makeRequest({ sessionId: created.sessionId }),
      LOCAL_ENTRY,
      deps(),
    );

    expect(result.ok).toBe(false);
    if (result.ok) {
      return;
    }
    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('returns verification context with bearerPlaintext when kind is verification (EV-04)', async () => {
    const created = await createKindSession('verification');

    const result = await loadVerificationMutationContext(
      makeRequest({ sessionId: created.sessionId }),
      LOCAL_ENTRY,
      deps(),
    );

    expect(result.ok).toBe(true);
    if (!result.ok) {
      return;
    }
    expect(result.ctx).toEqual({
      sessionId: created.sessionId,
      bearerPlaintext: FIXTURE_BEARER,
      kind: 'verification',
    });
  });
});
