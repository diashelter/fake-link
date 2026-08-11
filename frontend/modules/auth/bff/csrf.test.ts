import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NextResponse } from 'next/server';

import {
  CSRF_HEADER,
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  deriveCsrfToken,
  derivePreAuthCsrfToken,
  issueCsrfForSession,
  issuePreAuthCsrf,
  readPreAuthCsrfSid,
  validateCsrfDoubleSubmit,
} from './csrf';

const TEST_KEY = Buffer.alloc(32, 9).toString('base64');

afterEach(() => {
  vi.unstubAllEnvs();
});

function makeRequest(
  headers: Record<string, string>,
  cookies: Record<string, string> = {},
): Request {
  const cookieHeader = Object.entries(cookies)
    .map(([name, value]) => `${name}=${value}`)
    .join('; ');

  return new Request('https://app.localhost/api/bff/test', {
    method: 'POST',
    headers: {
      ...headers,
      ...(cookieHeader ? { cookie: cookieHeader } : {}),
    },
  });
}

describe('csrf double-submit', () => {
  beforeEach(() => {
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
  });

  it('accepts session-bound token when header and cookie match expected HMAC', () => {
    const sessionId = 'session-abc';
    const token = deriveCsrfToken(sessionId);
    const request = makeRequest({ [CSRF_HEADER]: token }, { [CSRF_TOKEN_COOKIE]: token });

    expect(validateCsrfDoubleSubmit(request, { mode: 'session', sessionId })).toEqual({ ok: true });
  });

  it('accepts pre-auth token when sid cookie is valid', () => {
    const csrfSid = 'pre-auth-sid';
    const token = derivePreAuthCsrfToken(csrfSid);
    const request = makeRequest({ [CSRF_HEADER]: token }, { [CSRF_TOKEN_COOKIE]: token });

    expect(validateCsrfDoubleSubmit(request, { mode: 'pre-auth', csrfSid })).toEqual({ ok: true });
  });

  it('rejects missing header or cookie', () => {
    const sessionId = 'session-abc';
    const token = deriveCsrfToken(sessionId);

    expect(
      validateCsrfDoubleSubmit(makeRequest({}, { [CSRF_TOKEN_COOKIE]: token }), {
        mode: 'session',
        sessionId,
      }),
    ).toEqual({ ok: false });

    expect(
      validateCsrfDoubleSubmit(makeRequest({ [CSRF_HEADER]: token }), {
        mode: 'session',
        sessionId,
      }),
    ).toEqual({ ok: false });
  });

  it('rejects header and cookie mismatch', () => {
    const sessionId = 'session-abc';
    const token = deriveCsrfToken(sessionId);

    expect(
      validateCsrfDoubleSubmit(
        makeRequest({ [CSRF_HEADER]: `${token}x` }, { [CSRF_TOKEN_COOKIE]: token }),
        { mode: 'session', sessionId },
      ),
    ).toEqual({ ok: false });
  });

  it('invalidates previous token after session rotation', () => {
    const oldSessionId = 'old-session';
    const newSessionId = 'new-session';
    const oldToken = deriveCsrfToken(oldSessionId);
    const request = makeRequest({ [CSRF_HEADER]: oldToken }, { [CSRF_TOKEN_COOKIE]: oldToken });

    expect(validateCsrfDoubleSubmit(request, { mode: 'session', sessionId: newSessionId })).toEqual(
      { ok: false },
    );

    const newToken = deriveCsrfToken(newSessionId);
    const rotatedRequest = makeRequest(
      { [CSRF_HEADER]: newToken },
      { [CSRF_TOKEN_COOKIE]: newToken },
    );

    expect(
      validateCsrfDoubleSubmit(rotatedRequest, { mode: 'session', sessionId: newSessionId }),
    ).toEqual({ ok: true });
  });

  it('issues session token cookie without HttpOnly and sid cookie with HttpOnly for pre-auth', () => {
    const sessionResponse = issueCsrfForSession('session-1', NextResponse.json({ ok: true }));
    const sessionCookies = sessionResponse.headers.getSetCookie();
    const csrfCookie = sessionCookies.find((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`));

    expect(csrfCookie).toBeDefined();
    expect(csrfCookie).not.toMatch(/HttpOnly/i);

    const preAuthResponse = issuePreAuthCsrf(NextResponse.json({ ok: true }), 'sid-123');
    const preAuthCookies = preAuthResponse.headers.getSetCookie();
    const sidCookie = preAuthCookies.find((value) => value.startsWith(`${CSRF_SID_COOKIE}=`));

    expect(sidCookie).toMatch(/HttpOnly/i);
  });

  it('reads pre-auth sid from request cookies', () => {
    const request = makeRequest({}, { [CSRF_SID_COOKIE]: 'sid-from-cookie' });

    expect(readPreAuthCsrfSid(request)).toBe('sid-from-cookie');
  });
});
