import type { ResponseCookie } from 'next/dist/compiled/@edge-runtime/cookies';
import { NextResponse } from 'next/server';

export const sessionCookieDefaults: Pick<
  ResponseCookie,
  'httpOnly' | 'secure' | 'sameSite' | 'path'
> = {
  httpOnly: true,
  secure: true,
  sameSite: 'lax',
  path: '/',
};

type SessionCookieOverrides = Omit<
  Partial<ResponseCookie>,
  'httpOnly' | 'secure' | 'sameSite'
>;

/** Merge options while forcing Secure / HttpOnly / SameSite=Lax (DOCKER-06). */
export function buildSessionCookieOptions(
  overrides: SessionCookieOverrides = {},
): Pick<ResponseCookie, 'httpOnly' | 'secure' | 'sameSite' | 'path'> &
  SessionCookieOverrides {
  return {
    ...overrides,
    ...sessionCookieDefaults,
  };
}

export function setSessionCookie(
  response: NextResponse,
  name: string,
  value: string,
  overrides: SessionCookieOverrides = {},
): NextResponse {
  response.cookies.set(name, value, buildSessionCookieOptions(overrides));
  return response;
}
