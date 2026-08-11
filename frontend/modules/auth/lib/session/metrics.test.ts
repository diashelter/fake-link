import { describe, expect, it } from 'vitest';

import { getDecryptFailCount, incrementDecryptFail } from './metrics';
import { FakeSessionStore } from './test/fake-session-store';
import type { SessionRecord } from './types';

const sampleRecord: SessionRecord = {
  schemaVersion: 1,
  kind: 'session',
  userId: '019082da-0000-7000-8000-000000000001',
  createdAt: '2026-08-11T12:00:00.000Z',
  lastActivityAt: '2026-08-11T12:00:00.000Z',
  envelope: { kid: '1', nonce: 'nonce', ciphertext: 'cipher' },
};

describe('session metrics (SC-07)', () => {
  it('increments decrypt-fail counter', () => {
    const before = getDecryptFailCount();

    incrementDecryptFail();
    incrementDecryptFail();

    expect(getDecryptFailCount()).toBe(before + 2);
  });
});

describe('FakeSessionStore', () => {
  it('supports get, set, and del for facade tests', async () => {
    const store = new FakeSessionStore();

    expect(await store.get('bff:sess:abc')).toBeNull();

    await store.set('bff:sess:abc', sampleRecord, 604_800);
    expect(await store.get('bff:sess:abc')).toEqual(sampleRecord);
    expect(store.getExSeconds('bff:sess:abc')).toBe(604_800);

    await store.del('bff:sess:abc');
    expect(await store.get('bff:sess:abc')).toBeNull();
  });
});
