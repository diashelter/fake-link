import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { SessionRecord } from './types';
import {
  ABSOLUTE_TTL_SECONDS,
  IDLE_TTL_SECONDS,
  TOUCH_THROTTLE_SECONDS,
  isAbsoluteExpired,
  isIdleExpired,
  remainingAbsoluteSeconds,
  shouldTouch,
} from './ttl';

function record(overrides: Partial<SessionRecord> = {}): SessionRecord {
  const now = new Date('2026-08-11T12:00:00.000Z');
  return {
    schemaVersion: 1,
    kind: 'session',
    userId: '019082da-0000-7000-8000-000000000001',
    createdAt: now.toISOString(),
    lastActivityAt: now.toISOString(),
    envelope: { kid: '1', nonce: 'n', ciphertext: 'c' },
    ...overrides,
  };
}

describe('session TTL helpers (SC-04, SC-08, SC-09, SC-10)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-08-11T12:00:00.000Z'));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('exposes absolute and idle TTL constants per kind', () => {
    expect(ABSOLUTE_TTL_SECONDS.session).toBe(604_800);
    expect(IDLE_TTL_SECONDS.session).toBe(86_400);
    expect(ABSOLUTE_TTL_SECONDS.verification).toBe(86_400);
    expect(IDLE_TTL_SECONDS.verification).toBe(3_600);
    expect(TOUCH_THROTTLE_SECONDS).toBe(900);
  });

  it('shouldTouch is false before 900s and true at/after 900s (SC-10)', () => {
    const lastActivityAt = new Date('2026-08-11T12:00:00.000Z');

    expect(shouldTouch(lastActivityAt, new Date('2026-08-11T12:14:59.000Z'))).toBe(false);
    expect(shouldTouch(lastActivityAt, new Date('2026-08-11T12:15:00.000Z'))).toBe(true);
    expect(shouldTouch(lastActivityAt, new Date('2026-08-11T12:20:00.000Z'))).toBe(true);
  });

  it('detects absolute expiry for session kind (SC-08)', () => {
    const created = record({ kind: 'session' });
    const now = new Date('2026-08-11T12:00:00.000Z');

    expect(isAbsoluteExpired(created, now)).toBe(false);
    expect(remainingAbsoluteSeconds(created, now)).toBe(604_800);

    vi.setSystemTime(new Date(now.getTime() + 604_800 * 1000 + 1));
    const afterAbsolute = new Date();
    expect(isAbsoluteExpired(created, afterAbsolute)).toBe(true);
    expect(remainingAbsoluteSeconds(created, afterAbsolute)).toBe(0);
  });

  it('detects absolute expiry for verification kind (SC-04 / SC-08)', () => {
    const created = record({ kind: 'verification' });
    const now = new Date('2026-08-11T12:00:00.000Z');

    expect(remainingAbsoluteSeconds(created, now)).toBe(86_400);

    vi.setSystemTime(new Date(now.getTime() + 86_400 * 1000 + 1));
    expect(isAbsoluteExpired(created, new Date())).toBe(true);
  });

  it('detects idle expiry for session (86400s) and verification (3600s) (SC-09)', () => {
    const session = record({ kind: 'session' });
    const verification = record({ kind: 'verification' });
    const base = new Date('2026-08-11T12:00:00.000Z');

    expect(isIdleExpired(session, base)).toBe(false);
    expect(isIdleExpired(verification, base)).toBe(false);

    vi.setSystemTime(new Date(base.getTime() + 86_400 * 1000 + 1));
    expect(isIdleExpired(session, new Date())).toBe(true);

    vi.setSystemTime(new Date(base.getTime() + 3_600 * 1000 + 1));
    expect(isIdleExpired(verification, new Date())).toBe(true);
  });
});
