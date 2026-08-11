import { describe, expect, it } from 'vitest';

import { sanitizeReturnUrl } from './return-url';

describe('sanitizeReturnUrl', () => {
  it('accepts safe internal paths', () => {
    expect(sanitizeReturnUrl('/dashboard')).toBe('/dashboard');
    expect(sanitizeReturnUrl('/links?q=1#x')).toBe('/links?q=1#x');
    expect(sanitizeReturnUrl('/login')).toBe('/login');
  });

  it('returns fallback for absolute and protocol-relative URLs', () => {
    expect(sanitizeReturnUrl('https://evil.com/x')).toBe('/');
    expect(sanitizeReturnUrl('//evil.com/x')).toBe('/');
  });

  it('returns fallback for backslash and encoded protocol-relative paths', () => {
    expect(sanitizeReturnUrl('/\\evil.com')).toBe('/');
    expect(sanitizeReturnUrl('/%2f%2fevil.com')).toBe('/');
  });

  it('returns fallback for @ and null bytes', () => {
    expect(sanitizeReturnUrl('/user@evil.com')).toBe('/');
    expect(sanitizeReturnUrl('/path%00evil')).toBe('/');
  });

  it('returns fallback for null undefined and empty input', () => {
    expect(sanitizeReturnUrl(null)).toBe('/');
    expect(sanitizeReturnUrl(undefined)).toBe('/');
    expect(sanitizeReturnUrl('')).toBe('/');
    expect(sanitizeReturnUrl('   ')).toBe('/');
  });

  it('returns fallback when input exceeds 2048 characters', () => {
    expect(sanitizeReturnUrl(`/${'a'.repeat(2048)}`)).toBe('/');
  });

  it('accepts decoded safe path from query-style input', () => {
    expect(sanitizeReturnUrl('/links')).toBe('/links');
  });

  it('uses explicit fallback when provided', () => {
    expect(sanitizeReturnUrl('https://evil.com', '/home')).toBe('/home');
  });
});
