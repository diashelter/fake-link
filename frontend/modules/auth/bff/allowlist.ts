import { getLaravelInternalUrl } from './env';
import type { AllowlistEntry, HttpMethod } from './types';

export type { AllowlistEntry, HttpMethod };

export const LOGIN_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/login',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/login',
  requireSession: false,
  requireCsrf: true,
};

export const REGISTER_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/register',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/register',
  requireSession: false,
  requireCsrf: true,
};

export const VERIFY_EMAIL_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/email/verify',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/email/verify',
  requireSession: true,
  requireCsrf: true,
};

export const RESEND_VERIFICATION_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/email/resend',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/email/verification-notification',
  requireSession: true,
  requireCsrf: true,
};

export const PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/password/reset-request',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/password/reset-request',
  requireSession: false,
  requireCsrf: true,
};

export const PASSWORD_RESET_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/password/reset',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/password/reset',
  requireSession: false,
  requireCsrf: true,
};

export const PASSWORD_CHANGE_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/password/change',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/password/change',
  requireSession: true,
  requireCsrf: true,
};

export const LOGOUT_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/logout',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/logout',
  requireSession: true,
  requireCsrf: true,
};

export const LOGOUT_ALL_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/logout-all',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/logout-all',
  requireSession: true,
  requireCsrf: true,
};

export const ME_GET_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'GET',
  bffPath: '/api/bff/auth/me',
  upstreamMethod: 'GET',
  upstreamPath: '/me',
  requireSession: true,
  requireCsrf: false,
};

export const ME_PATCH_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'PATCH',
  bffPath: '/api/bff/auth/me',
  upstreamMethod: 'PATCH',
  upstreamPath: '/me',
  requireSession: true,
  requireCsrf: true,
};

export const AUTH_BFF_ALLOWLIST: readonly AllowlistEntry[] = [
  LOGIN_ALLOWLIST_ENTRY,
  REGISTER_ALLOWLIST_ENTRY,
  VERIFY_EMAIL_ALLOWLIST_ENTRY,
  RESEND_VERIFICATION_ALLOWLIST_ENTRY,
  PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY,
  PASSWORD_RESET_ALLOWLIST_ENTRY,
  PASSWORD_CHANGE_ALLOWLIST_ENTRY,
  LOGOUT_ALLOWLIST_ENTRY,
  LOGOUT_ALL_ALLOWLIST_ENTRY,
  ME_GET_ALLOWLIST_ENTRY,
  ME_PATCH_ALLOWLIST_ENTRY,
];

export function lookupAllowlistEntry(
  method: string,
  bffPath: string,
  table: readonly AllowlistEntry[] = AUTH_BFF_ALLOWLIST,
): AllowlistEntry | undefined {
  const normalizedMethod = method.toUpperCase() as HttpMethod;

  return table.find((entry) => entry.method === normalizedMethod && entry.bffPath === bffPath);
}

export function buildUpstreamUrl(entry: AllowlistEntry): string {
  const base = getLaravelInternalUrl();
  const path = entry.upstreamPath.startsWith('/') ? entry.upstreamPath : `/${entry.upstreamPath}`;

  return `${base}${path}`;
}
