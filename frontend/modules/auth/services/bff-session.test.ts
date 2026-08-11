import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NextResponse } from 'next/server';

vi.mock('server-only', () => ({}));

import type { BffSessionConfig } from '../lib/session/config';
import { buildRedisSessionKey } from '../lib/session/redis-key';
import { parseSessionId } from '../lib/session/session-id';
import { FakeSessionStore } from '../lib/session/test/fake-session-store';
import { ABSOLUTE_TTL_SECONDS, IDLE_TTL_SECONDS, TOUCH_THROTTLE_SECONDS } from '../lib/session/ttl';
import { getDecryptFailCount } from '../lib/session/metrics';
import {
  applySessionCookie,
  clearSessionCookie,
  createSession,
  destroySession,
  getSession,
  getSessionFromRequest,
  rotateSession,
  SessionValidationError,
  touchSession,
} from './bff-session';

const TEST_BEARER = 'test-bearer-token-PLAINTEXT-secret-xyz';
const TEST_USER_ID = '019082da-0000-7000-8000-000000000001';

function testConfig(overrides: Partial<BffSessionConfig> = {}): BffSessionConfig {
  return {
    aesKey: Buffer.alloc(32, 7),
    hmacKey: Buffer.alloc(32, 9),
    aesKeyId: '1',
    cookieName: '__Host-fl_session',
    redisUrl: 'redis://redis-ephemeral:6379',
    probeEnabled: false,
    ...overrides,
  };
}

describe('createSession (SC-01, SC-02, SC-04)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
  });

  it('writes an encrypted record and returns opaque session id + expiresAt', async () => {
    const result = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    expect(result.sessionId).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(result.expiresAt.getTime()).toBe(
      fixedNow.getTime() + ABSOLUTE_TTL_SECONDS.session * 1000,
    );

    const idBytes = parseSessionId(result.sessionId);
    expect(idBytes).not.toBeNull();
    const key = buildRedisSessionKey(idBytes!, config.hmacKey);
    const record = await store.get(key);
    expect(record).not.toBeNull();
    expect(record!.schemaVersion).toBe(1);
    expect(record!.kind).toBe('session');
    expect(record!.userId).toBe(TEST_USER_ID);
    expect(record!.envelope.ciphertext).toBeTruthy();
    expect(store.getExSeconds(key)).toBe(ABSOLUTE_TTL_SECONDS.session);
  });

  it('stores Redis payload without Bearer plaintext (JSON.stringify)', async () => {
    const result = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    const idBytes = parseSessionId(result.sessionId)!;
    const record = await store.get(buildRedisSessionKey(idBytes, config.hmacKey));
    const serialized = JSON.stringify(record);

    expect(serialized).not.toContain(TEST_BEARER);
    expect(serialized).not.toContain('Authorization');
  });

  it('uses verification absolute TTL for verification kind (SC-04)', async () => {
    const result = await createSession(
      { bearer: TEST_BEARER, kind: 'verification', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    expect(result.expiresAt.getTime()).toBe(
      fixedNow.getTime() + ABSOLUTE_TTL_SECONDS.verification * 1000,
    );
  });

  it('rejects empty bearer before SET', async () => {
    const setSpy = vi.spyOn(store, 'set');

    await expect(
      createSession(
        { bearer: '', kind: 'session', userId: TEST_USER_ID },
        { config, store, now: () => fixedNow },
      ),
    ).rejects.toBeInstanceOf(SessionValidationError);

    await expect(
      createSession(
        { bearer: '   ', kind: 'session', userId: TEST_USER_ID },
        { config, store, now: () => fixedNow },
      ),
    ).rejects.toBeInstanceOf(SessionValidationError);

    expect(setSpy).not.toHaveBeenCalled();
  });
});

describe('applySessionCookie / clearSessionCookie (SC-03)', () => {
  const config = testConfig();

  it('Set-Cookie uses __Host-fl_session with Secure, HttpOnly, SameSite=Lax', () => {
    const response = NextResponse.json({ ok: true });
    applySessionCookie(response, 'a'.repeat(43), ABSOLUTE_TTL_SECONDS.session, { config });

    const setCookie = response.headers.getSetCookie?.() ?? [];
    const header =
      setCookie.find((value) => value.includes('__Host-fl_session=')) ??
      response.headers.get('set-cookie') ??
      '';

    expect(header).toContain('__Host-fl_session=');
    expect(header).toMatch(/HttpOnly/i);
    expect(header).toMatch(/Secure/i);
    expect(header).toMatch(/SameSite=Lax/i);
    expect(header).not.toMatch(/Domain=/i);
  });

  it('clearSessionCookie expires the cookie (Max-Age=0)', () => {
    const response = NextResponse.json({ ok: true });
    clearSessionCookie(response, { config });

    const setCookie = response.headers.getSetCookie?.() ?? [];
    const header =
      setCookie.find((value) => value.includes('__Host-fl_session=')) ??
      response.headers.get('set-cookie') ??
      '';

    expect(header).toContain('__Host-fl_session=');
    expect(header).toMatch(/Max-Age=0/i);
  });
});

describe('getSession (SC-05, SC-06, SC-07, SC-12)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
  });

  async function createAndCookie(): Promise<string> {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );
    return `${config.cookieName}=${created.sessionId}`;
  }

  it('happy path returns context with decrypted bearer in memory', async () => {
    const cookieHeader = await createAndCookie();

    const result = await getSession(cookieHeader, { config, store, now: () => fixedNow });

    expect(result.context).not.toBeNull();
    expect(result.context!.bearer).toBe(TEST_BEARER);
    expect(result.context!.userId).toBe(TEST_USER_ID);
    expect(result.context!.kind).toBe('session');
  });

  it('SessionContext holds bearer in memory; safe response shape must not (SC-05 negative)', async () => {
    const cookieHeader = await createAndCookie();
    const result = await getSession(cookieHeader, { config, store, now: () => fixedNow });
    const context = result.context!;

    // Bearer is present in the in-memory context (required for BFF upstream calls).
    expect(context.bearer).toBe(TEST_BEARER);
    // Negative: serializing SessionContext WOULD leak bearer — product must never do this.
    expect(JSON.stringify(context)).toContain(TEST_BEARER);
    // Probe/product-safe shape must omit bearer (leak detector).
    const safeShape = { authenticated: true, kind: context.kind };
    expect(JSON.stringify(safeShape)).not.toContain(TEST_BEARER);

    const idBytes = parseSessionId(context.sessionId)!;
    const record = await store.get(buildRedisSessionKey(idBytes, config.hmacKey));
    expect(JSON.stringify(record)).not.toContain(TEST_BEARER);
  });

  it('malformed cookie returns null without store get (SC-06)', async () => {
    const getSpy = vi.spyOn(store, 'get');

    const result = await getSession(`${config.cookieName}=not-a-valid-session-id`, {
      config,
      store,
      now: () => fixedNow,
    });

    expect(result).toEqual({ context: null, clearCookie: true });
    expect(getSpy).not.toHaveBeenCalled();
  });

  it('decrypt fail destroys session, increments metric, and clears cookie (SC-07)', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );
    const idBytes = parseSessionId(created.sessionId)!;
    const key = buildRedisSessionKey(idBytes, config.hmacKey);
    const record = await store.get(key);
    expect(record).not.toBeNull();

    const corrupted = {
      ...record!,
      envelope: {
        ...record!.envelope,
        ciphertext: `${record!.envelope.ciphertext.slice(0, -1)}${record!.envelope.ciphertext.endsWith('A') ? 'B' : 'A'}`,
      },
    };
    await store.set(key, corrupted, ABSOLUTE_TTL_SECONDS.session);

    const before = getDecryptFailCount();
    const result = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });

    expect(result).toEqual({ context: null, clearCookie: true });
    expect(getDecryptFailCount()).toBe(before + 1);
    expect(await store.get(key)).toBeNull();
  });
});

describe('touchSession + expiry enforcement (SC-08, SC-09, SC-10)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const base = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
    vi.useFakeTimers();
    vi.setSystemTime(base);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  async function createAtBase(kind: 'session' | 'verification' = 'session') {
    return createSession(
      { bearer: TEST_BEARER, kind, userId: TEST_USER_ID },
      { config, store, now: () => new Date() },
    );
  }

  it('idle expired session returns null, clearCookie, and destroys Redis key (SC-09)', async () => {
    const created = await createAtBase('session');
    const key = buildRedisSessionKey(parseSessionId(created.sessionId)!, config.hmacKey);

    vi.setSystemTime(new Date(base.getTime() + IDLE_TTL_SECONDS.session * 1000 + 1));

    const result = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => new Date(),
    });

    expect(result).toEqual({ context: null, clearCookie: true });
    expect(await store.get(key)).toBeNull();
  });

  it('absolute expired session is destroyed (SC-08)', async () => {
    const created = await createAtBase('session');
    const key = buildRedisSessionKey(parseSessionId(created.sessionId)!, config.hmacKey);
    const record = await store.get(key);
    // Keep idle fresh; force absolute expiry via createdAt in the past.
    await store.set(
      key,
      {
        ...record!,
        createdAt: new Date(base.getTime() - ABSOLUTE_TTL_SECONDS.session * 1000 - 1).toISOString(),
        lastActivityAt: base.toISOString(),
      },
      1,
    );

    const result = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => new Date(),
    });

    expect(result).toEqual({ context: null, clearCookie: true });
    expect(await store.get(key)).toBeNull();
  });

  it('touchSession does not write when elapsed < 900s (SC-10)', async () => {
    const created = await createAtBase('session');
    const key = buildRedisSessionKey(parseSessionId(created.sessionId)!, config.hmacKey);
    const setSpy = vi.spyOn(store, 'set');

    vi.setSystemTime(new Date(base.getTime() + (TOUCH_THROTTLE_SECONDS - 1) * 1000));
    setSpy.mockClear();

    await touchSession(created.sessionId, { config, store, now: () => new Date() });

    expect(setSpy).not.toHaveBeenCalled();
    const record = await store.get(key);
    expect(record!.lastActivityAt).toBe(base.toISOString());
  });

  it('touchSession updates lastActivityAt when elapsed ≥ 900s (SC-10)', async () => {
    const created = await createAtBase('session');
    const key = buildRedisSessionKey(parseSessionId(created.sessionId)!, config.hmacKey);

    const touchedAt = new Date(base.getTime() + TOUCH_THROTTLE_SECONDS * 1000);
    vi.setSystemTime(touchedAt);

    await touchSession(created.sessionId, { config, store, now: () => new Date() });

    const record = await store.get(key);
    expect(record!.lastActivityAt).toBe(touchedAt.toISOString());
    expect(store.getExSeconds(key)).toBe(ABSOLUTE_TTL_SECONDS.session - TOUCH_THROTTLE_SECONDS);
  });
});

describe('rotateSession / destroySession / Redis failure (SC-11, SC-12, SC-13)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
  });

  it('after rotate, old id does not resolve and new id does (SC-11)', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    const rotated = await rotateSession(created.sessionId, undefined, {
      config,
      store,
      now: () => fixedNow,
    });

    expect(rotated.sessionId).not.toBe(created.sessionId);

    const oldResult = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });
    expect(oldResult).toEqual({ context: null, clearCookie: true });

    const newResult = await getSession(`${config.cookieName}=${rotated.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });
    expect(newResult.context).not.toBeNull();
    expect(newResult.context!.bearer).toBe(TEST_BEARER);
  });

  it('concurrent rotateSession on same id leaves at most one valid successor', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );
    const deps = { config, store, now: () => fixedNow };

    const results = await Promise.allSettled([
      rotateSession(created.sessionId, undefined, deps),
      rotateSession(created.sessionId, undefined, deps),
    ]);

    const fulfilled = results.filter(
      (result): result is PromiseFulfilledResult<Awaited<ReturnType<typeof rotateSession>>> =>
        result.status === 'fulfilled',
    );
    const rejected = results.filter((result) => result.status === 'rejected');

    expect(fulfilled).toHaveLength(1);
    expect(rejected).toHaveLength(1);
    expect(rejected[0]).toMatchObject({
      status: 'rejected',
      reason: expect.any(SessionValidationError),
    });

    const oldResult = await getSession(`${config.cookieName}=${created.sessionId}`, deps);
    expect(oldResult).toEqual({ context: null, clearCookie: true });

    const successor = fulfilled[0]!.value;
    const newResult = await getSession(`${config.cookieName}=${successor.sessionId}`, deps);
    expect(newResult.context).not.toBeNull();
    expect(newResult.context!.bearer).toBe(TEST_BEARER);
  });

  it('destroySession removes Redis key and returns clearCookie (SC-11)', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );
    const key = buildRedisSessionKey(parseSessionId(created.sessionId)!, config.hmacKey);

    const destroyed = await destroySession(created.sessionId, { config, store });

    expect(destroyed).toEqual({ clearCookie: true });
    expect(await store.get(key)).toBeNull();

    const response = NextResponse.json({ ok: true });
    clearSessionCookie(response, { config });
    const setCookie = response.headers.getSetCookie?.() ?? [];
    const header =
      setCookie.find((value) => value.includes('__Host-fl_session=')) ??
      response.headers.get('set-cookie') ??
      '';
    expect(header).toMatch(/Max-Age=0/i);
  });

  it('store throw on getSession returns null + clearCookie (SC-13)', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );
    vi.spyOn(store, 'get').mockRejectedValueOnce(new Error('Redis connection failed'));

    const result = await getSession(`${config.cookieName}=${created.sessionId}`, {
      config,
      store,
      now: () => fixedNow,
    });

    expect(result).toEqual({ context: null, clearCookie: true });
  });
});

describe('getSessionFromRequest (LOG-11)', () => {
  let store: FakeSessionStore;
  let config: BffSessionConfig;
  const fixedNow = new Date('2026-08-11T12:00:00.000Z');

  beforeEach(() => {
    store = new FakeSessionStore();
    config = testConfig();
  });

  function makeRequest(cookieHeader: string | null): Request {
    return new Request('https://app.localhost/login', {
      headers: cookieHeader ? { cookie: cookieHeader } : {},
    });
  }

  it('returns null when cookie is absent', async () => {
    const summary = await getSessionFromRequest(makeRequest(null), {
      config,
      store,
      now: () => fixedNow,
    });
    expect(summary).toBeNull();
  });

  it('returns session metadata without bearer for valid cookie', async () => {
    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => fixedNow },
    );

    const summary = await getSessionFromRequest(
      makeRequest(`${config.cookieName}=${created.sessionId}`),
      { config, store, now: () => fixedNow },
    );

    expect(summary).toEqual({
      sessionId: created.sessionId,
      kind: 'session',
      userId: TEST_USER_ID,
    });
    expect(JSON.stringify(summary)).not.toContain(TEST_BEARER);
  });

  it('returns null for expired session cookie', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(fixedNow);

    const created = await createSession(
      { bearer: TEST_BEARER, kind: 'session', userId: TEST_USER_ID },
      { config, store, now: () => new Date() },
    );

    vi.setSystemTime(new Date(fixedNow.getTime() + IDLE_TTL_SECONDS.session * 1000 + 1));

    const summary = await getSessionFromRequest(
      makeRequest(`${config.cookieName}=${created.sessionId}`),
      { config, store, now: () => new Date() },
    );

    expect(summary).toBeNull();
    vi.useRealTimers();
  });
});
