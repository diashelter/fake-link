import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { derivePreAuthCsrfToken } from '@/modules/auth/bff';

import { GET, POST } from './route';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
  vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
  vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
  vi.stubEnv('NODE_ENV', 'test');
});

function makePostRequest(token?: string, csrfSid?: string): Request {
  const csrfSidValue = csrfSid ?? 'probe-sid';
  const csrfToken = token ?? derivePreAuthCsrfToken(csrfSidValue);

  return new Request('https://app.localhost/api/bff/_probe/mutate', {
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

describe('POST /api/bff/_probe/mutate', () => {
  it('returns upstream response on happy path', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { message: 'Logged in.' },
        {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        },
      ),
    );
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ message: 'Logged in.' });
    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/login',
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('rejects invalid Origin with forbidden response', async () => {
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
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('rejects CSRF mismatch with forbidden response', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest('invalid-token'));
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 404 in production NODE_ENV', async () => {
    vi.stubEnv('NODE_ENV', 'production');

    const response = await POST(makePostRequest());

    expect(response.status).toBe(404);
  });
});

describe('GET /api/bff/_probe/mutate', () => {
  it('returns probe ok outside production', async () => {
    const response = await GET();
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ probe: 'ok' });
  });

  it('returns 404 in production NODE_ENV', async () => {
    vi.stubEnv('NODE_ENV', 'production');

    const response = await GET();

    expect(response.status).toBe(404);
  });
});
