import { describe, expect, it } from 'vitest';

import { generateSessionId, parseSessionId } from './session-id';

const BASE64URL_RE = /^[A-Za-z0-9_-]+$/;

describe('session-id (SC-02, SC-06)', () => {
  it('generates a 43-character base64url opaque id', () => {
    const id = generateSessionId();

    expect(id).toHaveLength(43);
    expect(id).toMatch(BASE64URL_RE);
    expect(id.includes('=')).toBe(false);
    expect(id.includes('+')).toBe(false);
    expect(id.includes('/')).toBe(false);
  });

  it('generates ids that parse to exactly 32 bytes', () => {
    const id = generateSessionId();
    const bytes = parseSessionId(id);

    expect(bytes).not.toBeNull();
    expect(bytes!.byteLength).toBe(32);
  });

  it('rejects charset outside base64url alphabet', () => {
    const invalid = `${'A'.repeat(42)}+`;

    expect(invalid).toHaveLength(43);
    expect(parseSessionId(invalid)).toBeNull();
    expect(parseSessionId(`${'A'.repeat(42)}/`)).toBeNull();
    expect(parseSessionId('!!!not-base64url-chars!!!!!!!!!!!!!!')).toBeNull();
  });

  it('rejects wrong length', () => {
    expect(parseSessionId('A'.repeat(42))).toBeNull();
    expect(parseSessionId('A'.repeat(44))).toBeNull();
    expect(parseSessionId('')).toBeNull();
  });

  it('rejects encodings whose decoded payload is not 32 bytes', () => {
    // 16 bytes → shorter base64url (not 43 chars)
    const shortPayload = Buffer.alloc(16, 1).toString('base64url');
    expect(shortPayload.length).not.toBe(43);
    expect(parseSessionId(shortPayload)).toBeNull();

    // 31 bytes → 42 chars base64url (decode ≠ 32)
    const thirtyOne = Buffer.alloc(31, 2).toString('base64url');
    expect(thirtyOne).toHaveLength(42);
    expect(Buffer.from(thirtyOne, 'base64url').length).toBe(31);
    expect(parseSessionId(thirtyOne)).toBeNull();

    // 33 bytes → 44 chars base64url (decode ≠ 32)
    const thirtyThree = Buffer.alloc(33, 3).toString('base64url');
    expect(thirtyThree).toHaveLength(44);
    expect(Buffer.from(thirtyThree, 'base64url').length).toBe(33);
    expect(parseSessionId(thirtyThree)).toBeNull();
  });
});
