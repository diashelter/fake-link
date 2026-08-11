import 'server-only';

import { NextResponse } from 'next/server';

import { setSessionCookie } from '@/lib/session-cookie';

import { loadBffSessionConfig, type BffSessionConfig } from '../lib/session/config';
import { encryptBearer } from '../lib/session/crypto';
import { buildRedisSessionKey } from '../lib/session/redis-key';
import { generateSessionId, parseSessionId } from '../lib/session/session-id';
import { createSessionStore, type SessionStore } from '../lib/session/session-store';
import type { CreateSessionInput, CreateSessionResult, SessionRecord } from '../lib/session/types';
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
