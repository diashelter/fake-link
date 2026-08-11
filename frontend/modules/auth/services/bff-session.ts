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
  SessionKind,
  SessionRecord,
} from '../lib/session/types';
import {
  ABSOLUTE_TTL_SECONDS,
  isAbsoluteExpired,
  isIdleExpired,
  remainingAbsoluteSeconds,
  shouldTouch,
} from '../lib/session/ttl';

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

export type SessionSummary = {
  sessionId: string;
  kind: SessionKind;
  userId: string;
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
): Promise<boolean> {
  const sessionIdBytes = parseSessionId(sessionId);
  if (!sessionIdBytes) {
    return false;
  }
  return store.del(buildRedisSessionKey(sessionIdBytes, config.hmacKey));
}

/**
 * Read path: cookie → Redis GET → decrypt Bearer into in-memory SessionContext (SC-05–SC-07).
 */
export async function getSession(
  cookieHeader: string | null,
  deps: BffSessionDependencies = {},
): Promise<GetSessionResult> {
  const { config, store, now } = resolveDeps(deps);
  const cookieValue = extractCookieValue(cookieHeader, config.cookieName);
  if (!cookieValue) {
    return { context: null, clearCookie: true };
  }

  const sessionIdBytes = parseSessionId(cookieValue);
  if (!sessionIdBytes) {
    return { context: null, clearCookie: true };
  }

  const redisKey = buildRedisSessionKey(sessionIdBytes, config.hmacKey);
  let record: SessionRecord | null;
  try {
    record = await store.get(redisKey);
  } catch {
    // Redis connection/timeout errors → same as miss (SC-13).
    return { context: null, clearCookie: true };
  }
  if (!record) {
    return { context: null, clearCookie: true };
  }

  const current = now();
  if (isAbsoluteExpired(record, current) || isIdleExpired(record, current)) {
    await deleteSessionRecord(cookieValue, config, store);
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

/**
 * Throttled idle refresh: write lastActivityAt at most once per 900s (SC-10).
 */
export async function touchSession(
  sessionId: string,
  deps: BffSessionDependencies = {},
): Promise<void> {
  const { config, store, now } = resolveDeps(deps);
  const sessionIdBytes = parseSessionId(sessionId);
  if (!sessionIdBytes) {
    return;
  }

  const redisKey = buildRedisSessionKey(sessionIdBytes, config.hmacKey);
  const record = await store.get(redisKey);
  if (!record) {
    return;
  }

  const current = now();
  if (isAbsoluteExpired(record, current) || isIdleExpired(record, current)) {
    await store.del(redisKey);
    return;
  }

  if (!shouldTouch(new Date(record.lastActivityAt), current)) {
    return;
  }

  const updated: SessionRecord = {
    ...record,
    lastActivityAt: current.toISOString(),
  };
  const exSeconds = remainingAbsoluteSeconds(updated, current);
  if (exSeconds <= 0) {
    await store.del(redisKey);
    return;
  }
  await store.set(redisKey, updated, exSeconds);
}

/** Server-only session metadata without bearer — safe for RSC boundaries (LOG-11). */
export async function getSessionFromRequest(
  request: Request,
  deps: BffSessionDependencies = {},
): Promise<SessionSummary | null> {
  const result = await getSession(request.headers.get('cookie'), deps);
  if (!result.context) {
    return null;
  }

  const { sessionId, kind, userId } = result.context;
  return { sessionId, kind, userId };
}

/** Delete Redis session key and instruct cookie clear (SC-11). */
export async function destroySession(
  sessionId: string,
  deps: BffSessionDependencies = {},
): Promise<{ clearCookie: true }> {
  const { config, store } = resolveDeps(deps);
  await deleteSessionRecord(sessionId, config, store);
  return { clearCookie: true };
}

/**
 * Invalidate current session id and create a new one (delete-before-create) (SC-11).
 * When `input` is omitted, bearer/kind/userId are copied from the current record.
 * Concurrent rotates on the same id: only the caller that claims DEL may create (edge case).
 */
export async function rotateSession(
  currentSessionId: string,
  input?: CreateSessionInput,
  deps: BffSessionDependencies = {},
): Promise<CreateSessionResult> {
  const { config, store } = resolveDeps(deps);

  let createInput = input;
  if (!createInput) {
    const sessionIdBytes = parseSessionId(currentSessionId);
    if (!sessionIdBytes) {
      throw new SessionValidationError('Invalid current session id');
    }
    const record = await store.get(buildRedisSessionKey(sessionIdBytes, config.hmacKey));
    if (!record) {
      throw new SessionValidationError('Current session not found');
    }
    createInput = {
      bearer: decryptBearer(record.envelope, config),
      kind: record.kind,
      userId: record.userId,
    };
  }

  const claimed = await deleteSessionRecord(currentSessionId, config, store);
  if (!claimed) {
    throw new SessionValidationError('Current session not found');
  }
  return createSession(createInput, deps);
}
