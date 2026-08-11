import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';

import { buildRedisSessionKey } from './redis-key';

describe('buildRedisSessionKey (SC-06)', () => {
  it('prefixes HMAC-SHA256 hex with bff:sess:', () => {
    const sessionIdBytes = Buffer.alloc(32, 5);
    const hmacKey = Buffer.alloc(32, 9);

    const key = buildRedisSessionKey(new Uint8Array(sessionIdBytes), hmacKey);
    const expectedHex = createHmac('sha256', hmacKey).update(sessionIdBytes).digest('hex');

    expect(key).toBe(`bff:sess:${expectedHex}`);
    expect(key.startsWith('bff:sess:')).toBe(true);
    expect(key.slice('bff:sess:'.length)).toMatch(/^[0-9a-f]{64}$/);
  });

  it('is deterministic for the same inputs', () => {
    const bytes = new Uint8Array(32).fill(11);
    const hmacKey = Buffer.alloc(32, 3);

    expect(buildRedisSessionKey(bytes, hmacKey)).toBe(buildRedisSessionKey(bytes, hmacKey));
  });

  it('does not use raw session id bytes as the redis key', () => {
    const bytes = Buffer.from('abcdefghijklmnopqrstuvwxyz012345');
    const hmacKey = Buffer.alloc(32, 1);
    const key = buildRedisSessionKey(new Uint8Array(bytes), hmacKey);

    expect(key).not.toContain(bytes.toString('utf8'));
    expect(key).not.toContain(bytes.toString('base64url'));
  });
});
