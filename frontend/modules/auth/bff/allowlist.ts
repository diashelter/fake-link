import { getLaravelInternalUrl } from './env';
import type { AllowlistEntry, HttpMethod } from './types';

export type { AllowlistEntry, HttpMethod };

export const AUTH_BFF_ALLOWLIST: readonly AllowlistEntry[] = [];

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
