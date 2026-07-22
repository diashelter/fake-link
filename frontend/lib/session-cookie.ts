import type { ResponseCookie } from 'next/dist/compiled/@edge-runtime/cookies';

export const sessionCookieDefaults: Pick<
  ResponseCookie,
  'httpOnly' | 'secure' | 'sameSite' | 'path'
> = {
  httpOnly: true,
  secure: true,
  sameSite: 'lax',
  path: '/',
};
