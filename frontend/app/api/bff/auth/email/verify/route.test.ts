import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { NextResponse } from 'next/server';

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const EMAIL_TOKEN_SENTINEL = 'email-token-SENTINEL-do-not-leak';
const SUCCESS_MESSAGE = 'E-mail confirmado. Faça login para continuar.';

const verifyEmailHarness = vi.hoisted(() => {
  type Perform = typeof import('@/modules/auth/services/bff-verify-email').performBffVerifyEmail;
  return {
    mock: vi.fn<Perform>(),
    actual: null as Perform | null,
  };
});

vi.mock('@/modules/auth/services/bff-verify-email', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/modules/auth/services/bff-verify-email')>();
  verifyEmailHarness.actual = actual.performBffVerifyEmail;
  return {
    ...actual,
    performBffVerifyEmail: (
      ...args: Parameters<typeof actual.performBffVerifyEmail>
    ): ReturnType<typeof actual.performBffVerifyEmail> => verifyEmailHarness.mock(...args),
  };
});

import { POST } from './route';

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

beforeEach(() => {
  vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
  vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
  vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
  verifyEmailHarness.mock.mockReset();
  verifyEmailHarness.mock.mockImplementation((request, deps) => {
    const actual = verifyEmailHarness.actual;
    if (!actual) {
      throw new Error('performBffVerifyEmail actual implementation was not loaded');
    }
    return actual(request, deps);
  });
});

function makePostRequest(options?: { csrfToken?: string; origin?: string }): Request {
  const csrfToken = options?.csrfToken ?? 'bad-token';

  return new Request('https://app.localhost/api/bff/auth/email/verify', {
    method: 'POST',
    headers: {
      Origin: options?.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ token: EMAIL_TOKEN_SENTINEL }),
  });
}

describe('POST /api/bff/auth/email/verify', () => {
  it('returns 200 with expired session cookie and sanitized body on happy path (EV-01, EV-02, EV-03)', async () => {
    const mockedResponse = NextResponse.json(
      {
        data: {
          redirect_to: '/login',
          message: SUCCESS_MESSAGE,
        },
      },
      {
        status: 200,
        headers: {
          'Cache-Control': 'private, no-store',
          'Set-Cookie': '__Host-fl_session=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax',
        },
      },
    );
    verifyEmailHarness.mock.mockResolvedValue({
      ok: true,
      response: mockedResponse,
      success: { redirectTo: '/login', message: SUCCESS_MESSAGE },
    });

    const response = await POST(makePostRequest());
    const body = await response.json();
    const serialized = JSON.stringify(body);

    expect(response.status).toBe(200);
    expect(body).toEqual({
      data: { redirect_to: '/login', message: SUCCESS_MESSAGE },
    });
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
    expect(setCookies.some((value) => /Max-Age=0/i.test(value))).toBe(true);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain(EMAIL_TOKEN_SENTINEL);
    expect(serialized).not.toContain('token_kind');
    expect(serialized).not.toContain('token_type');
    expect(serialized).not.toContain('expires_at');
  });

  it('returns 403 on CSRF failure without calling upstream (EV-11)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ csrfToken: 'bad-token' }));
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on invalid Origin without calling upstream (EV-11)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest({ origin: 'https://evil.com' }));

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 when session cookie is missing without calling upstream (EV-11)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(
      new Request('https://app.localhost/api/bff/auth/email/verify', {
        method: 'POST',
        headers: {
          Origin: 'https://app.localhost',
          'X-CSRF-Token': 'not-a-session-csrf',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: EMAIL_TOKEN_SENTINEL }),
      }),
    );

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
