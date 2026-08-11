export { AUTH_BFF_ALLOWLIST, buildUpstreamUrl, lookupAllowlistEntry } from './allowlist';
export type { AllowlistEntry, HttpMethod } from './allowlist';

export {
  CSRF_HEADER,
  CSRF_SID_COOKIE,
  CSRF_TOKEN_COOKIE,
  deriveCsrfToken,
  derivePreAuthCsrfToken,
  issueCsrfForSession,
  issuePreAuthCsrf,
  readPreAuthCsrfSid,
  validateCsrfDoubleSubmit,
} from './csrf';

export { getBffAppOrigin, getCsrfHmacKey, getLaravelInternalUrl } from './env';
export { hmacSha256Base64Url, timingSafeEqualString } from './crypto';

export { assertMutationGuard } from './mutation-guard';
export { isMutationMethod, validateMutationOrigin } from './origin';
export {
  applyPrivateCacheHeaders,
  forbiddenResponse,
  jsonWithPrivateCache,
} from './private-response';
export { sanitizeReturnUrl } from './return-url';
export type {
  BffSessionRecord,
  CsrfContext,
  GuardResult,
  SessionLoader,
  UpstreamResult,
} from './types';
export { callAllowlistedUpstream } from './upstream';
