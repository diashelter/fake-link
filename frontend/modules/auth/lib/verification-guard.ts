export const VERIFICATION_ALLOWED_PATHS = [
  '/verify-email',
  '/login',
  '/terms',
  '/forgot-password',
  '/reset-password',
] as const;

export type VerificationGuardDecision =
  { action: 'allow' } | { action: 'redirect'; to: '/verify-email' | '/login' | '/' };

/**
 * Minimal restricted-session guard for the email-verification slice.
 *
 * Session-shell (BFFUI-52 / EV-17) should reuse `VERIFICATION_ALLOWED_PATHS`
 * and this helper: `verification` kind may only remain on those paths;
 * other App Router routes that apply the guard redirect to `/verify-email`.
 * Anonymous `/verify-email` visitors go to `/login`. Already-verified
 * (`session`) visitors on `/verify-email` go to `/`.
 */
export function resolveVerificationSessionGuard(input: {
  pathname: string;
  sessionKind: 'session' | 'verification' | null;
}): VerificationGuardDecision {
  const { pathname, sessionKind } = input;

  if (sessionKind === null) {
    if (pathname === '/verify-email') {
      return { action: 'redirect', to: '/login' };
    }

    return { action: 'allow' };
  }

  if (sessionKind === 'session') {
    if (pathname === '/verify-email') {
      return { action: 'redirect', to: '/' };
    }

    return { action: 'allow' };
  }

  const allowed: readonly string[] = VERIFICATION_ALLOWED_PATHS;
  if (allowed.includes(pathname)) {
    return { action: 'allow' };
  }

  return { action: 'redirect', to: '/verify-email' };
}
