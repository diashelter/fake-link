import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NextResponse } from 'next/server';

import {
  CSRF_HEADER,
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  deriveCsrfToken,
  derivePreAuthCsrfToken,
  ensurePreAuthCsrfCookies,
  clearCsrfCookies,
  issueCsrfForSession,
  issuePreAuthCsrf,
  readPreAuthCsrfSid,
  validateCsrfDoubleSubmit,
  writePreAuthCsrfCookies,
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

type MemoryCookieStore = {
  values: Map<string, { value: string; options?: Record<string, unknown> }>;
  set: (name: string, value: string, options?: Record<string, unknown>) => void;
  get: (name: string) => { value: string } | undefined;
};

function createMemoryCookieStore(initial: Record<string, string> = {}): MemoryCookieStore {
  const values = new Map<string, { value: string; options?: Record<string, unknown> }>(
    Object.entries(initial).map(([name, value]) => [name, { value }]),
  );

  return {
    values,
    set(name, value, options) {
      values.set(name, { value, options });
    },
    get(name) {
      const entry = values.get(name);
      return entry ? { value: entry.value } : undefined;
    },
  };
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

describe('writePreAuthCsrfCookies / ensurePreAuthCsrfCookies', () => {
  beforeEach(() => {
    vi.stubEnv('BFF_CSRF_HMAC_KEY', TEST_KEY);
  });

  it('issuePreAuthCsrf remains compatible with existing behavior', () => {
    const response = issuePreAuthCsrf(NextResponse.json({ ok: true }), 'sid-123');
    const cookies = response.headers.getSetCookie();

    expect(cookies.some((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`))).toBe(true);
    expect(cookies.some((value) => value.startsWith(`${CSRF_SID_COOKIE}=sid-123`))).toBe(true);
  });

  it('writePreAuthCsrfCookies emits sid and token cookies', () => {
    const store = createMemoryCookieStore();
    const sid = writePreAuthCsrfCookies(store, 'custom-sid');

    expect(sid).toBe('custom-sid');
    expect(store.get(CSRF_SID_COOKIE)?.value).toBe('custom-sid');
    expect(store.get(CSRF_TOKEN_COOKIE)?.value).toBe(derivePreAuthCsrfToken('custom-sid'));
  });

  it('ensurePreAuthCsrfCookies is idempotent when valid cookies exist', () => {
    const sid = 'existing-sid';
    const token = derivePreAuthCsrfToken(sid);
    const store = createMemoryCookieStore({
      [CSRF_SID_COOKIE]: sid,
      [CSRF_TOKEN_COOKIE]: token,
    });
    const setSpy = vi.spyOn(store, 'set');

    ensurePreAuthCsrfCookies(store);

    expect(setSpy).not.toHaveBeenCalled();
  });

  it('ensurePreAuthCsrfCookies emits cookies when sid or token is missing', () => {
    const store = createMemoryCookieStore();

    ensurePreAuthCsrfCookies(store);

    expect(store.get(CSRF_SID_COOKIE)?.value).toBeTruthy();
    expect(store.get(CSRF_TOKEN_COOKIE)?.value).toBe(
      derivePreAuthCsrfToken(store.get(CSRF_SID_COOKIE)!.value),
    );
  });

  it('ensurePreAuthCsrfCookies re-issues cookies when token does not match sid', () => {
    const store = createMemoryCookieStore({
      [CSRF_SID_COOKIE]: 'sid-a',
      [CSRF_TOKEN_COOKIE]: 'invalid-token',
    });

    ensurePreAuthCsrfCookies(store);

    expect(store.get(CSRF_TOKEN_COOKIE)?.value).toBe(
      derivePreAuthCsrfToken(store.get(CSRF_SID_COOKIE)!.value),
    );
  });
});

describe('clearCsrfCookies (SH-01)', () => {
  it('expires both CSRF cookies with Max-Age=0', () => {
    const response = clearCsrfCookies(NextResponse.json({ ok: true }));
    const cookies = response.headers.getSetCookie();
    const tokenCookie = cookies.find((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`));
    const sidCookie = cookies.find((value) => value.startsWith(`${CSRF_SID_COOKIE}=`));

    expect(tokenCookie).toBeDefined();
    expect(tokenCookie).toMatch(/Max-Age=0/i);
    expect(sidCookie).toBeDefined();
    expect(sidCookie).toMatch(/Max-Age=0/i);
  });

  it('uses the same __Host- attributes as the issue helpers', () => {
    const response = clearCsrfCookies(NextResponse.json({ ok: true }));
    const cookies = response.headers.getSetCookie();
    const tokenCookie = cookies.find((value) => value.startsWith(`${CSRF_TOKEN_COOKIE}=`));
    const sidCookie = cookies.find((value) => value.startsWith(`${CSRF_SID_COOKIE}=`));

    expect(tokenCookie).toMatch(/Secure/i);
    expect(tokenCookie).toMatch(/Path=\//i);
    expect(tokenCookie).toMatch(/SameSite=Lax/i);
    expect(tokenCookie).not.toMatch(/HttpOnly/i);
    expect(tokenCookie).not.toMatch(/Domain=/i);

    expect(sidCookie).toMatch(/Secure/i);
    expect(sidCookie).toMatch(/Path=\//i);
    expect(sidCookie).toMatch(/SameSite=Lax/i);
    expect(sidCookie).toMatch(/HttpOnly/i);
    expect(sidCookie).not.toMatch(/Domain=/i);
  });
});
