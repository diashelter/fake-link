import { describe, expect, it } from 'vitest';

import type { BffSessionConfig } from './config';
import { decryptBearer, encryptBearer, SessionDecryptError } from './crypto';

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

describe('AES-GCM bearer envelope (SC-01, SC-07, SC-15)', () => {
  it('round-trips encrypt then decrypt', () => {
    const config = testConfig();
    const bearer = 'test-bearer-token-value-xyz';

    const envelope = encryptBearer(bearer, config);
    const plaintext = decryptBearer(envelope, config);

    expect(plaintext).toBe(bearer);
    expect(envelope.kid).toBe('1');
    expect(envelope.nonce).toMatch(/^[A-Za-z0-9_-]+$/);
    expect(envelope.ciphertext).toMatch(/^[A-Za-z0-9_-]+$/);
  });

  it('uses distinct nonces across two writes', () => {
    const config = testConfig();
    const bearer = 'same-bearer-for-both-writes';

    const first = encryptBearer(bearer, config);
    const second = encryptBearer(bearer, config);

    expect(first.nonce).not.toBe(second.nonce);
    expect(first.ciphertext).not.toBe(second.ciphertext);
  });

  it('throws SessionDecryptError when GCM tag/ciphertext is invalid', () => {
    const config = testConfig();
    const envelope = encryptBearer('valid-bearer', config);
    const sealed = Buffer.from(envelope.ciphertext, 'base64url');
    sealed[0] ^= 0xff;
    const corrupted = {
      ...envelope,
      ciphertext: sealed.toString('base64url'),
    };

    expect(() => decryptBearer(corrupted, config)).toThrow(SessionDecryptError);
  });

  it('throws SessionDecryptError when kid is unknown (SC-15)', () => {
    const config = testConfig({ aesKeyId: '1' });
    const envelope = encryptBearer('valid-bearer', config);
    const unknownKid = { ...envelope, kid: '999' };

    expect(() => decryptBearer(unknownKid, config)).toThrow(SessionDecryptError);
  });

  it('does not include plaintext Bearer in serialized envelope (SC-01)', () => {
    const config = testConfig();
    const bearer = 'super-secret-bearer-plaintext-token';
    const envelope = encryptBearer(bearer, config);
    const serialized = JSON.stringify(envelope);

    expect(serialized).not.toContain(bearer);
    expect(serialized).not.toContain('Authorization');
    expect(serialized).toContain(envelope.ciphertext);
  });
});
