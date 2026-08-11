import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import type { BffSessionConfig } from './config';
import {
  createSessionStore,
  parseSessionRecord,
  serializeSessionRecord,
  type RedisSessionClient,
} from './session-store';
import type { SessionRecord } from './types';

const validRecord: SessionRecord = {
  schemaVersion: 1,
  kind: 'session',
  userId: '019082da-0000-7000-8000-000000000001',
  createdAt: '2026-08-11T12:00:00.000Z',
  lastActivityAt: '2026-08-11T12:00:00.000Z',
  envelope: {
    kid: '1',
    nonce: 'AAAAAAAAAAAAAAAA',
    ciphertext: 'ciphertext-value',
  },
};

function testConfig(): Pick<BffSessionConfig, 'redisUrl'> {
  return { redisUrl: 'redis://redis-ephemeral:6379' };
}

function createInjectedClient(
  overrides: Partial<RedisSessionClient> = {},
): RedisSessionClient {
  return {
    isOpen: true,
    connect: vi.fn().mockResolvedValue(undefined),
    get: vi.fn().mockResolvedValue(null),
    set: vi.fn().mockResolvedValue('OK'),
    del: vi.fn().mockResolvedValue(1),
    ...overrides,
  };
}

describe('serializeSessionRecord / parseSessionRecord (SC-01)', () => {
  it('round-trips a valid schemaVersion 1 record', () => {
    const json = serializeSessionRecord(validRecord);

    expect(parseSessionRecord(json)).toEqual(validRecord);
  });

  it('rejects schemaVersion other than 1', () => {
    const json = JSON.stringify({ ...validRecord, schemaVersion: 2 });

    expect(parseSessionRecord(json)).toBeNull();
  });

  it('rejects invalid kind', () => {
    const json = JSON.stringify({ ...validRecord, kind: 'admin' });

    expect(parseSessionRecord(json)).toBeNull();
  });

  it('rejects malformed JSON', () => {
    expect(parseSessionRecord('{')).toBeNull();
  });
});

describe('createSessionStore (injectable client, no real Redis)', () => {
  let client: RedisSessionClient;

  beforeEach(() => {
    client = createInjectedClient();
  });

  it('SET writes JSON with EX seconds', async () => {
    const store = createSessionStore(testConfig(), { client });

    await store.set('bff:sess:abc', validRecord, 3600);

    expect(client.set).toHaveBeenCalledWith(
      'bff:sess:abc',
      serializeSessionRecord(validRecord),
      { EX: 3600 },
    );
  });

  it('GET returns parsed SessionRecord', async () => {
    client = createInjectedClient({
      get: vi.fn().mockResolvedValue(serializeSessionRecord(validRecord)),
    });
    const store = createSessionStore(testConfig(), { client });

    const result = await store.get('bff:sess:abc');

    expect(result).toEqual(validRecord);
    expect(client.get).toHaveBeenCalledWith('bff:sess:abc');
  });

  it('GET returns null when stored JSON fails parse', async () => {
    client = createInjectedClient({
      get: vi.fn().mockResolvedValue(JSON.stringify({ ...validRecord, schemaVersion: 99 })),
    });
    const store = createSessionStore(testConfig(), { client });

    await expect(store.get('bff:sess:abc')).resolves.toBeNull();
  });

  it('DEL removes the key', async () => {
    const store = createSessionStore(testConfig(), { client });

    await store.del('bff:sess:abc');

    expect(client.del).toHaveBeenCalledWith('bff:sess:abc');
  });
});
