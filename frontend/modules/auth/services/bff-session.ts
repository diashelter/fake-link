import 'server-only';

import { NextResponse } from 'next/server';

import { setSessionCookie } from '@/lib/session-cookie';

import { loadBffSessionConfig, type BffSessionConfig } from '../lib/session/config';
import { decryptBearer, encryptBearer, SessionDecryptError } from '../lib/session/crypto';
import { incrementDecryptFail } from '../lib/session/metrics';
import { buildRedisSessionKey } from '../lib/session/redis-key';
import { generateSessionId, parseSessionId } from '../lib/session/session-id';
import { createSessionStore, type SessionStore } from '../lib/session/session-store';
import type {
  CreateSessionInput,
  CreateSessionResult,
  GetSessionResult,
  SessionRecord,
} from '../lib/session/types';
import { ABSOLUTE_TTL_SECONDS, remainingAbsoluteSeconds } from '../lib/session/ttl';

export class SessionValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SessionValidationError';
  }
}

export type BffSessionDependencies = {
  config?: BffSessionConfig;
  store?: SessionStore;
  now?: () => Date;
};

type ResolvedDeps = {
  config: BffSessionConfig;
  store: SessionStore;
  now: () => Date;
};

function resolveDeps(deps: BffSessionDependencies = {}): ResolvedDeps {
  const config = deps.config ?? loadBffSessionConfig();
  const store = deps.store ?? createSessionStore(config);
  const now = deps.now ?? (() => new Date());
  return { config, store, now };
}

export async function createSession(
  input: CreateSessionInput,
  deps: BffSessionDependencies = {},
): Promise<CreateSessionResult> {
  if (input.bearer.trim() === '') {
    throw new SessionValidationError('Bearer must not be empty');
  }

  const { config, store, now } = resolveDeps(deps);
  const createdAt = now();
  const sessionId = generateSessionId();
  const sessionIdBytes = parseSessionId(sessionId);
  if (!sessionIdBytes) {
    throw new Error('Failed to generate a valid session id');
  }

  const isoNow = createdAt.toISOString();
  const record: SessionRecord = {
    schemaVersion: 1,
    kind: input.kind,
    userId: input.userId,
    createdAt: isoNow,
    lastActivityAt: isoNow,
    envelope: encryptBearer(input.bearer, config),
  };

  const redisKey = buildRedisSessionKey(sessionIdBytes, config.hmacKey);
  const exSeconds = remainingAbsoluteSeconds(record, createdAt);
  await store.set(redisKey, record, exSeconds);

  return {
    sessionId,
    expiresAt: new Date(createdAt.getTime() + ABSOLUTE_TTL_SECONDS[input.kind] * 1000),
  };
}

export function applySessionCookie(
  response: NextResponse,
  sessionId: string,
  maxAge?: number,
  deps: BffSessionDependencies = {},
): NextResponse {
  const { config } = resolveDeps(deps);
  return setSessionCookie(response, config.cookieName, sessionId, {
    maxAge: maxAge ?? ABSOLUTE_TTL_SECONDS.session,
  });
}

export function clearSessionCookie(
  response: NextResponse,
  deps: BffSessionDependencies = {},
): NextResponse {
  const { config } = resolveDeps(deps);
  return setSessionCookie(response, config.cookieName, '', { maxAge: 0 });
}

function extractCookieValue(cookieHeader: string | null, name: string): string | null {
  if (!cookieHeader) {
    return null;
  }
  for (const part of cookieHeader.split(';')) {
    const trimmed = part.trim();
    const eq = trimmed.indexOf('=');
    if (eq === -1) {
      continue;
    }
    const key = trimmed.slice(0, eq);
    if (key === name) {
      return trimmed.slice(eq + 1);
    }
  }
  return null;
}

async function deleteSessionRecord(
  sessionId: string,
  config: BffSessionConfig,
  store: SessionStore,
): Promise<void> {
  const sessionIdBytes = parseSessionId(sessionId);
  if (!sessionIdBytes) {
    return;
  }
  await store.del(buildRedisSessionKey(sessionIdBytes, config.hmacKey));
}

/**
 * Read path: cookie → Redis GET → decrypt Bearer into in-memory SessionContext (SC-05–SC-07).
 */
export async function getSession(
  cookieHeader: string | null,
  deps: BffSessionDependencies = {},
): Promise<GetSessionResult> {
  const { config, store } = resolveDeps(deps);
  const cookieValue = extractCookieValue(cookieHeader, config.cookieName);
  if (!cookieValue) {
    return { context: null, clearCookie: true };
  }

  const sessionIdBytes = parseSessionId(cookieValue);
  if (!sessionIdBytes) {
    return { context: null, clearCookie: true };
  }

  const redisKey = buildRedisSessionKey(sessionIdBytes, config.hmacKey);
  const record = await store.get(redisKey);
  if (!record) {
    return { context: null, clearCookie: true };
  }

  let bearer: string;
  try {
    bearer = decryptBearer(record.envelope, config);
  } catch (error) {
    if (error instanceof SessionDecryptError) {
      incrementDecryptFail();
      await deleteSessionRecord(cookieValue, config, store);
      return { context: null, clearCookie: true };
    }
    throw error;
  }

  return {
    context: {
      sessionId: cookieValue,
      kind: record.kind,
      userId: record.userId,
      bearer,
      createdAt: new Date(record.createdAt),
      lastActivityAt: new Date(record.lastActivityAt),
    },
  };
}
