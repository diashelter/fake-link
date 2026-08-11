import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  deriveCsrfToken,
  derivePreAuthCsrfToken,
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
} from './csrf';
import { assertMutationGuard } from './mutation-guard';
import type { AllowlistEntry, BffSessionRecord } from './types';

const TEST_KEY = Buffer.alloc(32, 4).toString('base64');

const BASE_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/_probe/mutate',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/login',
  requireSession: false,
  requireCsrf: true,
};

const SESSION_ENTRY: AllowlistEntry = {
  ...BASE_ENTRY,
  requireSession: true,
};

afterEach(() => {
  vi.unstubAllEnvs();
});

beforeEach(() => {
  vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
  vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
});

function makeRequest(
  headers: Record<string, string> = {},
  cookies: Record<string, string> = {},
): Request {
  const cookieHeader = Object.entries(cookies)
    .map(([name, value]) => `${name}=${value}`)
    .join('; ');

  return new Request('https://app.localhost/api/bff/_probe/mutate', {
    method: 'POST',
    headers: {
      Origin: 'https://app.localhost',
      ...headers,
      ...(cookieHeader ? { cookie: cookieHeader } : {}),
    },
  });
}

describe('assertMutationGuard', () => {
  it('returns forbidden when session is required but loader is missing', async () => {
    const result = await assertMutationGuard(makeRequest(), SESSION_ENTRY);

    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.response.status).toBe(403);
      expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
    }
  });

  it('returns forbidden when session is required but absent', async () => {
    const loadSession = vi.fn(async () => null);
    const result = await assertMutationGuard(makeRequest(), SESSION_ENTRY, { loadSession });

    expect(result.ok).toBe(false);
    expect(loadSession).toHaveBeenCalledOnce();
  });

  it('returns forbidden on invalid Origin without calling session loader', async () => {
    const loadSession = vi.fn(async () => null);
    const request = makeRequest({ Origin: 'https://evil.com' });
    const result = await assertMutationGuard(request, BASE_ENTRY, { loadSession });

    expect(result.ok).toBe(false);
    expect(loadSession).not.toHaveBeenCalled();
  });

  it('returns forbidden on CSRF failure without calling session loader for pre-auth', async () => {
    const loadSession = vi.fn(async () => null);
    const result = await assertMutationGuard(makeRequest(), BASE_ENTRY, { loadSession });

    expect(result.ok).toBe(false);
    expect(loadSession).not.toHaveBeenCalled();
  });

  it('passes when Origin and pre-auth CSRF are valid', async () => {
    const csrfSid = 'sid-123';
    const token = derivePreAuthCsrfToken(csrfSid);
    const request = makeRequest(
      { 'X-CSRF-Token': token },
      { [CSRF_TOKEN_COOKIE]: token, [CSRF_SID_COOKIE]: csrfSid },
    );

    const result = await assertMutationGuard(request, BASE_ENTRY);

    expect(result).toEqual({ ok: true, session: null });
  });

  it('passes when session-bound CSRF is valid', async () => {
    const session: BffSessionRecord = {
      sessionId: 'session-1',
      bearerPlaintext: 'secret-bearer',
    };
    const validToken = deriveCsrfToken(session.sessionId);
    const request = makeRequest(
      { 'X-CSRF-Token': validToken },
      { [CSRF_TOKEN_COOKIE]: validToken },
    );

    const loadSession = vi.fn(async () => session);
    const result = await assertMutationGuard(request, SESSION_ENTRY, { loadSession });

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.session).toEqual(session);
    }
  });
});
