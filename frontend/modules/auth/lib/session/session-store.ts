import 'server-only';

import { createClient } from 'redis';

import type { BffSessionConfig } from './config';
import type { SessionEnvelope, SessionKind, SessionRecord } from './types';

const SESSION_KINDS: ReadonlySet<SessionKind> = new Set(['session', 'verification']);

/** Minimal Redis surface used by the session store (injectable for tests). */
export type RedisSessionClient = {
  isOpen: boolean;
  connect(): Promise<unknown>;
  get(key: string): Promise<string | null>;
  set(key: string, value: string, options: { EX: number }): Promise<unknown>;
  del(key: string): Promise<unknown>;
};

export interface SessionStore {
  get(key: string): Promise<SessionRecord | null>;
  set(key: string, record: SessionRecord, exSeconds: number): Promise<void>;
  /** @returns true when the key existed and was removed. */
  del(key: string): Promise<boolean>;
}

export type CreateSessionStoreOptions = {
  client?: RedisSessionClient;
};

let lazyClient: RedisSessionClient | null = null;

function isSessionEnvelope(value: unknown): value is SessionEnvelope {
  if (typeof value !== 'object' || value === null) {
    return false;
  }
  const envelope = value as Record<string, unknown>;
  return (
    typeof envelope.kid === 'string' &&
    typeof envelope.nonce === 'string' &&
    typeof envelope.ciphertext === 'string'
  );
}

function isSessionRecord(value: unknown): value is SessionRecord {
  if (typeof value !== 'object' || value === null) {
    return false;
  }
  const record = value as Record<string, unknown>;
  if (record.schemaVersion !== 1) {
    return false;
  }
  if (typeof record.kind !== 'string' || !SESSION_KINDS.has(record.kind as SessionKind)) {
    return false;
  }
  return (
    typeof record.userId === 'string' &&
    typeof record.createdAt === 'string' &&
    typeof record.lastActivityAt === 'string' &&
    isSessionEnvelope(record.envelope)
  );
}

export function serializeSessionRecord(record: SessionRecord): string {
  return JSON.stringify(record);
}

export function parseSessionRecord(json: string): SessionRecord | null {
  try {
    const parsed: unknown = JSON.parse(json);
    return isSessionRecord(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

async function ensureConnected(client: RedisSessionClient): Promise<RedisSessionClient> {
  if (!client.isOpen) {
    await client.connect();
  }
  return client;
}

function getOrCreateClient(redisUrl: string): RedisSessionClient {
  if (!lazyClient) {
    const client = createClient({ url: redisUrl });
    client.on('error', () => {
      // Connection errors surface to callers as thrown Redis failures (SC-12/SC-13).
      // Never log secrets, bearer, or raw session ids.
    });
    lazyClient = client as unknown as RedisSessionClient;
  }
  return lazyClient;
}

/**
 * Redis-backed SessionStore. Pass `{ client }` in tests — never hit real Redis from Vitest.
 */
export function createSessionStore(
  config: Pick<BffSessionConfig, 'redisUrl'>,
  options: CreateSessionStoreOptions = {},
): SessionStore {
  const resolveClient = async (): Promise<RedisSessionClient> => {
    if (options.client) {
      return ensureConnected(options.client);
    }
    return ensureConnected(getOrCreateClient(config.redisUrl));
  };

  return {
    async get(key: string): Promise<SessionRecord | null> {
      const client = await resolveClient();
      const raw = await client.get(key);
      if (raw === null) {
        return null;
      }
      return parseSessionRecord(raw);
    },

    async set(key: string, record: SessionRecord, exSeconds: number): Promise<void> {
      const client = await resolveClient();
      await client.set(key, serializeSessionRecord(record), { EX: exSeconds });
    },

    async del(key: string): Promise<boolean> {
      const client = await resolveClient();
      const removed = await client.del(key);
      return Number(removed) > 0;
    },
  };
}
