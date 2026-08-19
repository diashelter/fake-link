import { resolveVerificationSessionGuard } from './verification-guard';

export function isAccountPath(pathname: string): boolean {
  return pathname === '/settings' || pathname.startsWith('/settings/');
}

export function resolveAccountPageGuard(input: {
  pathname: string;
  sessionKind: 'session' | 'verification' | null;
}): { action: 'allow' } | { action: 'redirect'; to: '/login' | '/verify-email' | '/' } {
  const { pathname, sessionKind } = input;

  if (isAccountPath(pathname)) {
    if (sessionKind === null) {
      return { action: 'redirect', to: '/login' };
    }

    if (sessionKind === 'verification') {
      return { action: 'redirect', to: '/verify-email' };
    }

    return { action: 'allow' };
  }

  return resolveVerificationSessionGuard({ pathname, sessionKind });
}
