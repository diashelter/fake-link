import { createClient } from 'redis';
import { buildRedisSessionKey } from '../../modules/auth/lib/session/redis-key';
import { parseSessionId } from '../../modules/auth/lib/session/session-id';
import type { SessionRecord } from '../../modules/auth/lib/session/types';

const redisUrl = (): string => {
  const url = process.env.E2E_REDIS_URL;
  if (!url) throw new Error('E2E_REDIS_URL is not set');
  return url;
};

const hmacKey = (): Buffer => {
  const key = process.env.BFF_SESSION_HMAC_KEY;
  if (!key) throw new Error('BFF_SESSION_HMAC_KEY is not set');
  return Buffer.from(key, 'hex');
};

/** Connect, FLUSHDB, disconnect. */
export async function flushEphemeralRedis(): Promise<void> {
  const client = createClient({ url: redisUrl() });
  await client.connect();
  try {
    await client.flushDb();
  } finally {
    await client.disconnect();
  }
}

/** Derive the Redis key for a session cookie value. */
function deriveKey(sessionId: string): string {
  const bytes = parseSessionId(sessionId);
  if (!bytes) throw new Error(`Invalid sessionId: ${sessionId}`);
  return buildRedisSessionKey(bytes, hmacKey());
}

/** Read and parse a session record from Redis, or null if absent. */
export async function readSessionRecord(sessionId: string): Promise<SessionRecord | null> {
  const client = createClient({ url: redisUrl() });
  await client.connect();
  try {
    const raw = await client.get(deriveKey(sessionId));
    if (raw === null) return null;
    return JSON.parse(raw) as SessionRecord;
  } finally {
    await client.disconnect();
  }
}

/**
 * Rewrite createdAt / lastActivityAt backdated by the given deltas (in seconds),
 * preserving the remaining TTL.
 */
export async function backdateSessionRecord(
  sessionId: string,
  opts: { createdAtDeltaS?: number; lastActivityDeltaS?: number },
): Promise<void> {
  const client = createClient({ url: redisUrl() });
  await client.connect();
  try {
    const key = deriveKey(sessionId);
    const [raw, ttl] = await Promise.all([client.get(key), client.ttl(key)]);
    if (raw === null) throw new Error(`Session not found in Redis: ${sessionId}`);

    const record = JSON.parse(raw) as SessionRecord;

    if (opts.createdAtDeltaS !== undefined) {
      const ts = new Date(record.createdAt);
      ts.setSeconds(ts.getSeconds() - opts.createdAtDeltaS);
      record.createdAt = ts.toISOString();
    }
    if (opts.lastActivityDeltaS !== undefined) {
      const ts = new Date(record.lastActivityAt);
      ts.setSeconds(ts.getSeconds() - opts.lastActivityDeltaS);
      record.lastActivityAt = ts.toISOString();
    }

    if (ttl > 0) {
      await client.set(key, JSON.stringify(record), { EX: ttl });
    } else {
      await client.set(key, JSON.stringify(record));
    }
  } finally {
    await client.disconnect();
  }
}
