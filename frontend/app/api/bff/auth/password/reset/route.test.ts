import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { derivePreAuthCsrfToken } from '@/modules/auth/bff/csrf';
import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import { POST } from './route';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const TOKEN_SENTINEL = 'reset-token-SENTINEL-do-not-leak';
const PASSWORD_SENTINEL = 'Abcdefghij1!';
const RESET_SUCCESS_MESSAGE = 'Senha redefinida. Faça login para continuar.';

const VALID_BODY = {
  email: 'user@example.com',
  token: TOKEN_SENTINEL,
  password: PASSWORD_SENTINEL,
  password_confirmation: PASSWORD_SENTINEL,
};

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

beforeEach(() => {
  vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
  vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
  vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
});

function makePostRequest(options?: { csrfToken?: string; origin?: string }): Request {
  const csrfSid = 'reset-route-sid';
  const csrfToken = options?.csrfToken ?? derivePreAuthCsrfToken(csrfSid);

  return new Request('https://app.localhost/api/bff/auth/password/reset', {
    method: 'POST',
    headers: {
      Origin: options?.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}; __Host-fl_csrf_sid=${csrfSid}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(VALID_BODY),
  });
}

describe('POST /api/bff/auth/password/reset', () => {
  it('returns 200 with expired session cookie and sanitized body on happy path (PW-06, PW-07, BFFUI-61)', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());
    const body = await response.json();
    const serialized = JSON.stringify(body);

    expect(response.status).toBe(200);
    expect(body).toEqual({
      data: { redirect_to: '/login', message: RESET_SUCCESS_MESSAGE },
    });
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
    expect(setCookies.some((value) => /Max-Age=0/i.test(value))).toBe(true);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain(TOKEN_SENTINEL);
    expect(serialized).not.toContain(PASSWORD_SENTINEL);
  });

  it('returns 403 on CSRF failure without calling upstream (PW-19)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ csrfToken: 'bad-token' }));
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on invalid Origin without calling upstream (PW-19)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ origin: 'https://evil.com' }));

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
