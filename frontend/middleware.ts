import type { NextRequest } from 'next/server';
import { NextResponse } from 'next/server';

import { sessionCookieDefaults } from './lib/session-cookie';

/**
 * Keep session cookie security defaults on the request graph (DOCKER-06).
 * Local HTTPS must not ship with relaxed Secure / HttpOnly / SameSite.
 */
export function middleware(_request: NextRequest) {
  if (
    sessionCookieDefaults.secure !== true ||
    sessionCookieDefaults.httpOnly !== true ||
    sessionCookieDefaults.sameSite !== 'lax'
  ) {
    throw new Error('Session cookie defaults must not relax Secure, HttpOnly, or SameSite=Lax.');
  }

  return NextResponse.next();
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
