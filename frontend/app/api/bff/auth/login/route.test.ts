import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { derivePreAuthCsrfToken } from '@/modules/auth/bff/csrf';
import { buildUpstreamAuthPayload, FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import { POST } from './route';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

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

function makePostRequest(token?: string, csrfSid?: string): Request {
  const csrfSidValue = csrfSid ?? 'login-route-sid';
  const csrfToken = token ?? derivePreAuthCsrfToken(csrfSidValue);

  return new Request('https://app.localhost/api/bff/auth/login', {
    method: 'POST',
    headers: {
      Origin: 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}; __Host-fl_csrf_sid=${csrfSidValue}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email: 'user@example.com', password: 'secret' }),
  });
}

describe('POST /api/bff/auth/login', () => {
  it('returns 200 with session cookie and body without token on happy path', async () => {
    const fetchMock = vi.fn(async () => Response.json(buildUpstreamAuthPayload(), { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body.data.redirect_to).toBe('/');
    expect(JSON.stringify(body)).not.toContain(FIXTURE_BEARER);
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
  });

  it('returns 403 on CSRF failure without calling upstream', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest('bad-token'));
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on invalid Origin without calling upstream', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const request = makePostRequest();
    const badOriginRequest = new Request(request, {
      headers: {
        Origin: 'https://evil.com',
        'X-CSRF-Token': request.headers.get('X-CSRF-Token') ?? '',
        cookie: request.headers.get('cookie') ?? '',
        'Content-Type': 'application/json',
      },
    });

    const response = await POST(badOriginRequest);

    expect(response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('delegates upstream 401 to performBffLogin pass-through', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ code: 'INVALID_CREDENTIALS', message: 'Invalid.' }, { status: 401 }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());

    expect(response.status).toBe(401);
    expect(await response.json()).toMatchObject({ code: 'INVALID_CREDENTIALS' });
  });
});
