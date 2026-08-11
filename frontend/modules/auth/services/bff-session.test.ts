import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NextResponse } from 'next/server';

vi.mock('server-only', () => ({}));

import type { BffSessionConfig } from '../lib/session/config';
import { buildRedisSessionKey } from '../lib/session/redis-key';
import { parseSessionId } from '../lib/session/session-id';
import { FakeSessionStore } from '../lib/session/test/fake-session-store';
import { ABSOLUTE_TTL_SECONDS } from '../lib/session/ttl';
import {
  applySessionCookie,
  clearSessionCookie,
  createSession,
  SessionValidationError,
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
