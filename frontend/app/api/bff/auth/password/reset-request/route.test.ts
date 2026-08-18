import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { derivePreAuthCsrfToken } from '@/modules/auth/bff/csrf';

import { POST } from './route';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

const ACCEPTED_ENVELOPE = {
  message: 'If the email is registered, you will receive password reset instructions.',
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

function makePostRequest(options?: { csrfToken?: string; origin?: string; email?: string }): Request {
  const csrfSid = 'reset-request-route-sid';
  const csrfToken = options?.csrfToken ?? derivePreAuthCsrfToken(csrfSid);

  return new Request('https://app.localhost/api/bff/auth/password/reset-request', {
    method: 'POST',
    headers: {
      Origin: options?.origin ?? 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}; __Host-fl_csrf_sid=${csrfSid}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email: options?.email ?? 'user@example.com' }),
  });
}

describe('POST /api/bff/auth/password/reset-request', () => {
  it('returns 202 and passes through the upstream envelope on happy path (PW-01, BFFUI-60)', async () => {
    const fetchMock = vi.fn(async () => Response.json(ACCEPTED_ENVELOPE, { status: 202 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());
    const body = await response.json();

    expect(response.status).toBe(202);
    expect(body).toEqual(ACCEPTED_ENVELOPE);
    expect(fetchMock).toHaveBeenCalledOnce();
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
