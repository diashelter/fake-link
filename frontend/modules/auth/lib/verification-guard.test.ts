import { describe, expect, it } from 'vitest';

import {
  resolveVerificationSessionGuard,
  VERIFICATION_ALLOWED_PATHS,
} from './verification-guard';

describe('VERIFICATION_ALLOWED_PATHS (EV-17)', () => {
  it('exports the restricted-session allowlist for session-shell', () => {
    expect(VERIFICATION_ALLOWED_PATHS).toEqual(['/verify-email', '/login', '/terms']);
  });
});

describe('resolveVerificationSessionGuard (EV-16, EV-17)', () => {
  it('redirects an anonymous visitor on /verify-email to /login', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/verify-email', sessionKind: null }),
    ).toEqual({ action: 'redirect', to: '/login' });
  });

  it('redirects a session-kind visitor on /verify-email to /', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/verify-email', sessionKind: 'session' }),
    ).toEqual({ action: 'redirect', to: '/' });
  });

  it('redirects a verification-kind visitor on / to /verify-email', () => {
    expect(resolveVerificationSessionGuard({ pathname: '/', sessionKind: 'verification' })).toEqual(
      { action: 'redirect', to: '/verify-email' },
    );
  });

  it('allows a verification-kind visitor on /verify-email', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/verify-email', sessionKind: 'verification' }),
    ).toEqual({ action: 'allow' });
  });

  it('allows a verification-kind visitor on /login', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/login', sessionKind: 'verification' }),
    ).toEqual({ action: 'allow' });
  });

  it('allows a verification-kind visitor on /terms', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/terms', sessionKind: 'verification' }),
    ).toEqual({ action: 'allow' });
  });

  it('redirects a verification-kind visitor on a path outside the allowlist to /verify-email', () => {
    expect(
      resolveVerificationSessionGuard({ pathname: '/dashboard', sessionKind: 'verification' }),
    ).toEqual({ action: 'redirect', to: '/verify-email' });
  });

  it('allows a session-kind visitor on paths other than /verify-email', () => {
    expect(resolveVerificationSessionGuard({ pathname: '/', sessionKind: 'session' })).toEqual({
      action: 'allow',
    });
    expect(
      resolveVerificationSessionGuard({ pathname: '/dashboard', sessionKind: 'session' }),
    ).toEqual({ action: 'allow' });
  });

  it('allows an anonymous visitor on the public landing /', () => {
    expect(resolveVerificationSessionGuard({ pathname: '/', sessionKind: null })).toEqual({
      action: 'allow',
    });
  });
});
