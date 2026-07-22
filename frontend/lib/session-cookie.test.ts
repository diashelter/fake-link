import { describe, expect, it } from 'vitest';
import { NextResponse } from 'next/server';

import {
  buildSessionCookieOptions,
  sessionCookieDefaults,
  setSessionCookie,
} from './session-cookie';

describe('session cookie defaults (DOCKER-06)', () => {
  it('keeps Secure, HttpOnly, and SameSite=Lax', () => {
    expect(sessionCookieDefaults).toEqual({
      httpOnly: true,
      secure: true,
      sameSite: 'lax',
      path: '/',
    });
  });

  it('does not relax security flags when merging overrides', () => {
    const options = buildSessionCookieOptions({
      maxAge: 3600,
      path: '/dashboard',
    });

    expect(options.httpOnly).toBe(true);
    expect(options.secure).toBe(true);
    expect(options.sameSite).toBe('lax');
    expect(options.maxAge).toBe(3600);
    expect(options.path).toBe('/');
  });

  it('writes Secure, HttpOnly, and SameSite=Lax on Set-Cookie', () => {
    const response = setSessionCookie(NextResponse.json({ ok: true }), 'fl_session', 'token');
    const setCookie = response.headers.getSetCookie?.() ?? [];
    const header =
      setCookie.find((value) => value.startsWith('fl_session=')) ??
      response.headers.get('set-cookie') ??
      '';

    expect(header).toMatch(/HttpOnly/i);
    expect(header).toMatch(/Secure/i);
    expect(header).toMatch(/SameSite=Lax/i);
  });
});
