import { describe, expect, it } from 'vitest';

import { hmacSha256Base64Url, timingSafeEqualString } from './crypto';

const TEST_KEY = Buffer.alloc(32, 7);

describe('hmacSha256Base64Url', () => {
  it('is deterministic for a fixed key and message', () => {
    const first = hmacSha256Base64Url(TEST_KEY, 'session-id-123');
    const second = hmacSha256Base64Url(TEST_KEY, 'session-id-123');

    expect(first).toBe(second);
    expect(first).toMatch(/^[A-Za-z0-9_-]+$/);
  });

  it('changes output when message changes', () => {
    const first = hmacSha256Base64Url(TEST_KEY, 'session-a');
    const second = hmacSha256Base64Url(TEST_KEY, 'session-b');

    expect(first).not.toBe(second);
  });
});

describe('timingSafeEqualString', () => {
  it('returns true for equal strings', () => {
    expect(timingSafeEqualString('abc', 'abc')).toBe(true);
  });

  it('returns false for different strings of equal length', () => {
    expect(timingSafeEqualString('abc', 'abd')).toBe(false);
  });

  it('returns false for different lengths without throwing', () => {
    expect(timingSafeEqualString('abc', 'abcd')).toBe(false);
  });
});
