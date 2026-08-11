const DEFAULT_TERMS_VERSION = '2026-01';

/**
 * Current Terms version shown in the browser (UI only).
 * Aligns with backend AUTH_TERMS_CURRENT_VERSION; not sent in register body.
 */
export function getAuthTermsCurrentVersion(): string {
  return process.env.NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION ?? DEFAULT_TERMS_VERSION;
}
