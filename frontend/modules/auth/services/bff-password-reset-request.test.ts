import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import {
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  derivePreAuthCsrfToken,
} from '@/modules/auth/bff/csrf';
import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';
import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import { performBffPasswordResetRequest } from './bff-password-reset-request';
import * as bffSession from './bff-session';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

const ACCEPTED_ENVELOPE = {
  message: 'If the email is registered, you will receive password reset instructions.',
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

function makeRequest(
  options: {
    body?: unknown;
    csrfSid?: string;
    origin?: string;
    csrfToken?: string;
    contentType?: string | null;
  } = {},
): Request {
  const csrfSid = options.csrfSid ?? 'reset-request-sid';
  const csrfToken = options.csrfToken ?? derivePreAuthCsrfToken(csrfSid);
  const body = options.body === undefined ? { email: 'user@example.com' } : options.body;
  const headers = new Headers({
    Origin: options.origin ?? 'https://app.localhost',
    'X-CSRF-Token': csrfToken,
    cookie: `${CSRF_TOKEN_COOKIE}=${csrfToken}; ${CSRF_SID_COOKIE}=${csrfSid}`,
  });
  if (options.contentType !== null) {
    headers.set('Content-Type', options.contentType ?? 'application/json');
  }

  return new Request('https://app.localhost/api/bff/auth/password/reset-request', {
    method: 'POST',
    headers,
    body: body === null ? undefined : JSON.stringify(body),
  });
}

describe('performBffPasswordResetRequest (PW-01–03, PW-19–21)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');
  let getSessionSpy: ReturnType<typeof vi.spyOn>;
  let destroySessionSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
    getSessionSpy = vi.spyOn(bffSession, 'getSession');
    destroySessionSpy = vi.spyOn(bffSession, 'destroySession');
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  function deps(fetchImpl: typeof fetch) {
    return { config, store, now: () => fixedNow, fetchImpl };
  }

  function expectNoSessionSideEffects() {
    expect(getSessionSpy).not.toHaveBeenCalled();
    expect(destroySessionSpy).not.toHaveBeenCalled();
  }

  it('passes through identical 202 envelopes for existing and ineligible emails (PW-01, PW-02)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const existing = await performBffPasswordResetRequest(
      makeRequest({ body: { email: 'user@example.com' } }),
      deps(fetchMock),
    );
    const ineligible = await performBffPasswordResetRequest(
      makeRequest({ body: { email: 'nobody@example.com' } }),
      deps(fetchMock),
    );

    expect(existing.response.status).toBe(202);
    expect(ineligible.response.status).toBe(202);
    const existingBody = await existing.response.json();
    const ineligibleBody = await ineligible.response.json();
    expect(existingBody).toEqual(ACCEPTED_ENVELOPE);
    expect(ineligibleBody).toEqual(existingBody);
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      'http://nginx/api/v1/auth/password/reset-request',
      expect.objectContaining({
        body: JSON.stringify({ email: 'user@example.com' }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      'http://nginx/api/v1/auth/password/reset-request',
      expect.objectContaining({
        body: JSON.stringify({ email: 'nobody@example.com' }),
      }),
    );
    expectNoSessionSideEffects();
  });

  it('does not emit or mutate a session cookie on 202 (PW-03)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(202);
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    const setCookies = result.response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(false);
    expectNoSessionSideEffects();
  });

  it('forwards only the email field and drops extra body keys (PW-18)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    await performBffPasswordResetRequest(
      makeRequest({ body: { email: 'user@example.com', token: 'drop-me', extra: true } }),
      deps(fetchMock),
    );

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/password/reset-request',
      expect.objectContaining({
        body: JSON.stringify({ email: 'user@example.com' }),
      }),
    );
    expect(JSON.stringify(fetchMock.mock.calls[0]?.[1])).not.toContain('drop-me');
  });

  it('forwards upstream 422 without session side effects (PW-01)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'VALIDATION_FAILED', message: 'Invalid.', errors: { email: ['bad'] } },
        { status: 422 },
      ),
    );

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(422);
    expect(await result.response.json()).toMatchObject({ code: 'VALIDATION_FAILED' });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expectNoSessionSideEffects();
  });

  it('forwards upstream 429 with Retry-After (PW-20)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
        { status: 429, headers: { 'Retry-After': '60' } },
      ),
    );

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(429);
    expect(result.response.headers.get('Retry-After')).toBe('60');
    expect(await result.response.json()).toMatchObject({ code: 'RATE_LIMIT_EXCEEDED' });
    expectNoSessionSideEffects();
  });

  it('returns 504 generic pt-BR when upstream fetch aborts (PW-21)', async () => {
    const fetchMock = vi.fn(async () => {
      throw new Error('timeout');
    });

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(504);
    expect(await result.response.json()).toEqual({
      message: 'Não foi possível conectar ao serviço. Tente novamente.',
    });
    expectNoSessionSideEffects();
  });

  it('returns generic pt-BR message for upstream 500 (PW-21)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Internal stack trace' }, { status: 500 }),
    );

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(500);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expectNoSessionSideEffects();
  });

  it('returns generic pt-BR message for upstream 503 (PW-21)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ message: 'Service unavailable' }, { status: 503 }),
    );

    const result = await performBffPasswordResetRequest(makeRequest(), deps(fetchMock));

    expect(result.response.status).toBe(503);
    expect(await result.response.json()).toEqual({
      message: 'Algo deu errado. Tente novamente.',
    });
    expectNoSessionSideEffects();
  });

  it('returns 400 for malformed JSON without upstream fetch (PW-18)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));
    const request = new Request('https://app.localhost/api/bff/auth/password/reset-request', {
      method: 'POST',
      headers: makeRequest().headers,
      body: '{ invalid',
    });

    const result = await performBffPasswordResetRequest(request, deps(fetchMock));

    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expectNoSessionSideEffects();
  });

  it('returns 400 for missing Content-Type without upstream fetch (PW-18)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffPasswordResetRequest(
      makeRequest({ contentType: null }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(400);
    expect(await result.response.json()).toEqual({ message: 'Requisição inválida.' });
    expect(fetchMock).not.toHaveBeenCalled();
    expectNoSessionSideEffects();
  });

  it('returns 403 and does not fetch when Origin is invalid (PW-19)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffPasswordResetRequest(
      makeRequest({ origin: 'https://evil.com' }),
      deps(fetchMock),
    );

    expect(result.response.status).toBe(403);
    expect(await result.response.json()).toEqual({ message: 'Forbidden.' });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(fetchMock).not.toHaveBeenCalled();
    expectNoSessionSideEffects();
  });

  it('success JSON does not contain password or Bearer sentinels (PW-22)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));

    const result = await performBffPasswordResetRequest(
      makeRequest({ body: { email: 'user@example.com', password: 'Abcdefghij1!' } }),
      deps(fetchMock),
    );

    const serialized = JSON.stringify(await result.response.json());
    expect(serialized).not.toContain('Abcdefghij1!');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('Bearer');
  });
});
