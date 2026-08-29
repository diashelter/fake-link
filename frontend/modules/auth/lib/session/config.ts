import { ABSOLUTE_TTL_SECONDS, IDLE_TTL_SECONDS } from './ttl';
import type { SessionKind } from './types';

export interface BffSessionConfig {
  aesKey: Buffer;
  hmacKey: Buffer;
  aesKeyId: string;
  cookieName: string;
  redisUrl: string;
  probeEnabled: boolean;
  absoluteTtlSeconds: Record<SessionKind, number>;
  idleTtlSeconds: Record<SessionKind, number>;
}

function requireEnv(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === '') {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function decodeBase64Key(name: string, value: string): Buffer {
  const key = Buffer.from(value, 'base64');
  if (key.length === 0) {
    throw new Error(`Malformed ${name}: decoded key is empty`);
  }
  return key;
}

function parseTtlEnv(name: string): number | undefined {
  const raw = process.env[name];
  if (raw === undefined || raw === '') {
    return undefined;
  }
  const n = Number(raw);
  if (!Number.isInteger(n) || n <= 0) {
    throw new Error(
      `Invalid environment variable ${name}: expected a positive integer, got ${JSON.stringify(raw)}`,
    );
  }
  return n;
}

/**
 * Load and validate BFF session env vars. Fail-fast on missing/malformed keys (SC-14).
 */
export function loadBffSessionConfig(): BffSessionConfig {
  const aesKeyRaw = requireEnv('BFF_SESSION_AES_KEY');
  const hmacKeyRaw = requireEnv('BFF_SESSION_HMAC_KEY');
  const redisHost = requireEnv('REDIS_HOST');
  const redisPort = requireEnv('REDIS_PORT');

  const aesKey = decodeBase64Key('BFF_SESSION_AES_KEY', aesKeyRaw);
  if (aesKey.length !== 32) {
    throw new Error(
      `Malformed BFF_SESSION_AES_KEY: expected 32 decoded bytes, got ${aesKey.length}`,
    );
  }

  const hmacKey = decodeBase64Key('BFF_SESSION_HMAC_KEY', hmacKeyRaw);
  if (hmacKey.length < 32) {
    throw new Error(
      `Malformed BFF_SESSION_HMAC_KEY: expected at least 32 decoded bytes, got ${hmacKey.length}`,
    );
  }

  const aesKeyId = process.env.BFF_SESSION_AES_KEY_ID || '1';
  const cookieName = process.env.BFF_SESSION_COOKIE_NAME || '__Host-fl_session';
  const probeEnabled = process.env.BFF_SESSION_PROBE_ENABLED === 'true';

  const absoluteTtlSeconds: Record<SessionKind, number> = {
    session: parseTtlEnv('BFF_SESSION_ABSOLUTE_TTL_SESSION') ?? ABSOLUTE_TTL_SECONDS.session,
    verification:
      parseTtlEnv('BFF_SESSION_ABSOLUTE_TTL_VERIFICATION') ?? ABSOLUTE_TTL_SECONDS.verification,
  };

  const idleTtlSeconds: Record<SessionKind, number> = {
    session: parseTtlEnv('BFF_SESSION_IDLE_TTL_SESSION') ?? IDLE_TTL_SECONDS.session,
    verification:
      parseTtlEnv('BFF_SESSION_IDLE_TTL_VERIFICATION') ?? IDLE_TTL_SECONDS.verification,
  };

  return {
    aesKey,
    hmacKey,
    aesKeyId,
    cookieName,
    redisUrl: `redis://${redisHost}:${redisPort}`,
    probeEnabled,
    absoluteTtlSeconds,
    idleTtlSeconds,
  };
}
