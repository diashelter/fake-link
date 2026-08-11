import { createHmac, timingSafeEqual } from 'node:crypto';

export function hmacSha256Base64Url(key: Buffer, message: string): string {
  return createHmac('sha256', key).update(message, 'utf8').digest('base64url');
}

export function timingSafeEqualString(a: string, b: string): boolean {
  const left = Buffer.from(a, 'utf8');
  const right = Buffer.from(b, 'utf8');

  if (left.length !== right.length) {
    return false;
  }

  return timingSafeEqual(left, right);
}
