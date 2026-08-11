import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import { derivePreAuthCsrfToken } from '@/modules/auth/bff/csrf';
import {
  buildUpstreamAuthPayload,
  FIXTURE_BEARER,
  FIXTURE_USER,
} from '@/modules/auth/lib/test/auth-fixtures';

import { POST } from './route';

const TEST_KEY = Buffer.alloc(32, 2).toString('base64');

const PENDING_USER = {
  ...FIXTURE_USER,
  status: 'pending_verification' as const,
  email_verified_at: null,
  terms_version: '2026-01',
  terms_accepted_at: '2026-08-11T12:00:00.000Z',
};

const VALID_BODY = {
  name: 'Helter Dias',
  email: 'user@example.com',
  password: 'Abcdefghij1!',
  password_confirmation: 'Abcdefghij1!',
  accept_terms: true,
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

function makePostRequest(token?: string, csrfSid?: string): Request {
  const csrfSidValue = csrfSid ?? 'register-route-sid';
  const csrfToken = token ?? derivePreAuthCsrfToken(csrfSidValue);

  return new Request('https://app.localhost/api/bff/auth/register', {
    method: 'POST',
    headers: {
      Origin: 'https://app.localhost',
      'X-CSRF-Token': csrfToken,
      cookie: `__Host-fl_csrf=${csrfToken}; __Host-fl_csrf_sid=${csrfSidValue}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(VALID_BODY),
  });
}

describe('POST /api/bff/auth/register', () => {
  it('returns 201 with session cookie and body without token on happy path (RGR-01, RGR-03)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(buildUpstreamAuthPayload({ token_kind: 'verification', user: PENDING_USER }), {
        status: 201,
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());
    const body = await response.json();

    expect(response.status).toBe(201);
    expect(body.data.redirect_to).toBe('/verify-email');
    expect(body.data.user.status).toBe('pending_verification');
    expect(JSON.stringify(body)).not.toContain(FIXTURE_BEARER);
    const setCookies = response.headers.getSetCookie?.() ?? [];
    expect(setCookies.some((value) => value.includes('__Host-fl_session='))).toBe(true);
  });

  it('returns 403 on CSRF failure without calling upstream (RGR-12)', async () => {
    const fetchMock = vi.fn(async () => Response.json({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest('bad-token'));
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 403 on invalid Origin without calling upstream (RGR-12)', async () => {
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

  it('delegates upstream 403 REGISTRATION_NOT_ALLOWED to performBffRegister pass-through (RGR-04)', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { code: 'REGISTRATION_NOT_ALLOWED', message: 'Registration not allowed.' },
        { status: 403 },
      ),
    );
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(makePostRequest());

    expect(response.status).toBe(403);
    expect(await response.json()).toMatchObject({ code: 'REGISTRATION_NOT_ALLOWED' });
  });
});
