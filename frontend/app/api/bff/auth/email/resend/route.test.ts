import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { NextResponse } from 'next/server';

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');
const ACCEPTED_ENVELOPE = { message: 'Accepted.' };

const resendHarness = vi.hoisted(() => {
  type Perform =
    typeof import('@/modules/auth/services/bff-resend-verification').performBffResendVerification;
  return {
    mock: vi.fn<Perform>(),
    actual: null as Perform | null,
  };
});

vi.mock('@/modules/auth/services/bff-resend-verification', async (importOriginal) => {
  const actual =
    await importOriginal<typeof import('@/modules/auth/services/bff-resend-verification')>();
  resendHarness.actual = actual.performBffResendVerification;
  return {
    ...actual,
    performBffResendVerification: (
      ...args: Parameters<typeof actual.performBffResendVerification>
    ): ReturnType<typeof actual.performBffResendVerification> => resendHarness.mock(...args),
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
  resendHarness.mock.mockReset();
  resendHarness.mock.mockImplementation((request, deps) => {
    const actual = resendHarness.actual;
    if (!actual) {
      throw new Error('performBffResendVerification actual implementation was not loaded');
    }
    return actual(request, deps);
  });
});

function makePostRequest(options?: { csrfToken?: string; origin?: string }): Request {
  const csrfToken = options?.csrfToken ?? 'bad-token';

  return new Request('https://app.localhost/api/bff/auth/email/resend', {
    method: 'POST',
    headers: {
      Origin: options?.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}`,
    },
  });
}

describe('POST /api/bff/auth/email/resend', () => {
  it('passes through upstream 202 Accepted envelope on happy path (EV-05)', async () => {
    const mockedResponse = NextResponse.json(ACCEPTED_ENVELOPE, {
      status: 202,
      headers: {
        'Cache-Control': 'private, no-store',
      },
    });
    resendHarness.mock.mockResolvedValue({
      ok: true,
      response: mockedResponse,
    });

    const response = await POST(makePostRequest());
    const body = await response.json();
    const serialized = JSON.stringify(body);

    expect(response.status).toBe(202);
    expect(body).toEqual(ACCEPTED_ENVELOPE);
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => /Max-Age=0/i.test(value))).toBe(false);
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
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
      new Request('https://app.localhost/api/bff/auth/email/resend', {
        method: 'POST',
        headers: {
          Origin: 'https://app.localhost',
          'X-CSRF-Token': 'not-a-session-csrf',
        },
      }),
    );

    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
