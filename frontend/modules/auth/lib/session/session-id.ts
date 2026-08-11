import { randomBytes } from 'node:crypto';

const SESSION_ID_BYTES = 32;
const SESSION_ID_LENGTH = 43;
const BASE64URL_RE = /^[A-Za-z0-9_-]+$/;

/** Generate a 256-bit opaque session id as base64url (43 chars, no padding). */
export function generateSessionId(): string {
  return randomBytes(SESSION_ID_BYTES).toString('base64url');
}

/**
 * Strictly parse a cookie session id.
 * Returns null when charset, length, or decoded byte length is invalid (SC-06).
 */
export function parseSessionId(value: string): Uint8Array | null {
  if (value.length !== SESSION_ID_LENGTH || !BASE64URL_RE.test(value)) {
    return null;
  }

  const bytes = Buffer.from(value, 'base64url');
  if (bytes.length !== SESSION_ID_BYTES) {
    return null;
  }

  return new Uint8Array(bytes);
}
