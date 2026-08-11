import { createHmac } from 'node:crypto';

const REDIS_SESSION_PREFIX = 'bff:sess:';

/** Build Redis key: bff:sess: + HMAC-SHA256(hmacKey, sessionIdBytes) as lowercase hex. */
export function buildRedisSessionKey(sessionIdBytes: Uint8Array, hmacKey: Buffer): string {
  const digest = createHmac('sha256', hmacKey).update(sessionIdBytes).digest('hex');
  return `${REDIS_SESSION_PREFIX}${digest}`;
}
