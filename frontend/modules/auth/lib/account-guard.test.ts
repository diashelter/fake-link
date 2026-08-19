import { describe, expect, it } from 'vitest';

import { isAccountPath, resolveAccountPageGuard } from './account-guard';

describe('isAccountPath (SH-18, SH-19)', () => {
  it('treats /settings and nested settings paths as account routes', () => {
    expect(isAccountPath('/settings')).toBe(true);
    expect(isAccountPath('/settings/password')).toBe(true);
  });

  it('does not treat public landing as an account path', () => {
    expect(isAccountPath('/')).toBe(false);
  });
});

describe('resolveAccountPageGuard (SH-18, SH-19, SH-20)', () => {
  it('redirects a guest on /settings to /login', () => {
    expect(resolveAccountPageGuard({ pathname: '/settings', sessionKind: null })).toEqual({
      action: 'redirect',
      to: '/login',
    });
  });

  it('redirects a guest on /settings/password to /login', () => {
    expect(resolveAccountPageGuard({ pathname: '/settings/password', sessionKind: null })).toEqual({
      action: 'redirect',
      to: '/login',
    });
  });

  it('redirects a verification-kind visitor on /settings to /verify-email', () => {
    expect(resolveAccountPageGuard({ pathname: '/settings', sessionKind: 'verification' })).toEqual(
      { action: 'redirect', to: '/verify-email' },
    );
  });

  it('redirects a verification-kind visitor on /settings/password to /verify-email', () => {
    expect(
      resolveAccountPageGuard({ pathname: '/settings/password', sessionKind: 'verification' }),
    ).toEqual({ action: 'redirect', to: '/verify-email' });
  });

  it('allows a session-kind visitor on /settings', () => {
    expect(resolveAccountPageGuard({ pathname: '/settings', sessionKind: 'session' })).toEqual({
      action: 'allow',
    });
  });

  it('allows a session-kind visitor on /settings/password', () => {
    expect(
      resolveAccountPageGuard({ pathname: '/settings/password', sessionKind: 'session' }),
    ).toEqual({ action: 'allow' });
  });

  it('redirects a verification-kind visitor on / to /verify-email', () => {
    expect(resolveAccountPageGuard({ pathname: '/', sessionKind: 'verification' })).toEqual({
      action: 'redirect',
      to: '/verify-email',
    });
  });

  it('redirects a session-kind visitor on /verify-email to /', () => {
    expect(resolveAccountPageGuard({ pathname: '/verify-email', sessionKind: 'session' })).toEqual({
      action: 'redirect',
      to: '/',
    });
  });
});
