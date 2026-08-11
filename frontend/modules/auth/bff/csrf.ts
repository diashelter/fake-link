import { randomBytes } from 'node:crypto';

import { NextResponse } from 'next/server';
import type { ResponseCookie } from 'next/dist/compiled/@edge-runtime/cookies';

import { buildSessionCookieOptions } from '@/lib/session-cookie';

import { hmacSha256Base64Url, timingSafeEqualString } from './crypto';
import { getCsrfHmacKey } from './env';
import type { CsrfContext } from './types';

export const CSRF_TOKEN_COOKIE = '__Host-fl_csrf';
export const CSRF_SID_COOKIE = '__Host-fl_csrf_sid';
export const CSRF_HEADER = 'X-CSRF-Token';
const PRE_AUTH_MAX_AGE = 3600;

const csrfTokenCookieDefaults = {
  httpOnly: false,
  secure: true,
  sameSite: 'lax' as const,
  path: '/',
};

type CookieWriter = {
  set: (name: string, value: string, options?: Partial<ResponseCookie>) => void;
};

type PreAuthCookieStore = CookieWriter & {
  get: (name: string) => { value: string } | undefined;
};

function readCookie(request: Request, name: string): string | null {
  const cookieHeader = request.headers.get('cookie');
  if (!cookieHeader) {
    return null;
  }

  for (const part of cookieHeader.split(';')) {
    const [rawName, ...rest] = part.trim().split('=');
    if (rawName === name) {
      return rest.join('=');
    }
  }

  return null;
}

export function deriveCsrfToken(sessionId: string): string {
  return hmacSha256Base64Url(getCsrfHmacKey(), sessionId);
}

export function derivePreAuthCsrfToken(csrfSid: string): string {
  return hmacSha256Base64Url(getCsrfHmacKey(), csrfSid);
}

export function issueCsrfForSession(sessionId: string, response: NextResponse): NextResponse {
  const token = deriveCsrfToken(sessionId);
  response.cookies.set(CSRF_TOKEN_COOKIE, token, csrfTokenCookieDefaults);
  return response;
}

export function writePreAuthCsrfCookies(store: CookieWriter, csrfSid?: string): string {
  const sid = csrfSid ?? randomBytes(32).toString('base64url');
  const token = derivePreAuthCsrfToken(sid);

  store.set(CSRF_TOKEN_COOKIE, token, csrfTokenCookieDefaults);
  store.set(CSRF_SID_COOKIE, sid, buildSessionCookieOptions({ maxAge: PRE_AUTH_MAX_AGE }));

  return sid;
}

export function issuePreAuthCsrf(response: NextResponse, csrfSid?: string): NextResponse {
  writePreAuthCsrfCookies(response.cookies, csrfSid);
  return response;
}

export function ensurePreAuthCsrfCookies(cookies: PreAuthCookieStore): void {
  const sid = cookies.get(CSRF_SID_COOKIE)?.value;
  const token = cookies.get(CSRF_TOKEN_COOKIE)?.value;

  if (sid && token && timingSafeEqualString(token, derivePreAuthCsrfToken(sid))) {
    return;
  }

  writePreAuthCsrfCookies(cookies, undefined);
}

function expectedToken(ctx: CsrfContext): string {
  return ctx.mode === 'session'
    ? deriveCsrfToken(ctx.sessionId)
    : derivePreAuthCsrfToken(ctx.csrfSid);
}

export function validateCsrfDoubleSubmit(
  request: Request,
  ctx: CsrfContext,
): { ok: true } | { ok: false } {
  const headerToken = request.headers.get(CSRF_HEADER);
  const cookieToken = readCookie(request, CSRF_TOKEN_COOKIE);

  if (!headerToken || !cookieToken) {
    return { ok: false };
  }

  if (!timingSafeEqualString(headerToken, cookieToken)) {
    return { ok: false };
  }

  const expected = expectedToken(ctx);

  if (!timingSafeEqualString(cookieToken, expected)) {
    return { ok: false };
  }

  return { ok: true };
}

export function readPreAuthCsrfSid(request: Request): string | null {
  return readCookie(request, CSRF_SID_COOKIE);
}
