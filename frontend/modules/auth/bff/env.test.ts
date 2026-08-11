import { afterEach, describe, expect, it, vi } from 'vitest';

import { getBffAppOrigin, getCsrfHmacKey, getLaravelInternalUrl } from './env';

const ORIGINAL_ENV = { ...process.env };

afterEach(() => {
  process.env = { ...ORIGINAL_ENV };
  vi.unstubAllEnvs();
});

describe('getBffAppOrigin', () => {
  it('returns BFF_APP_ORIGIN when set', () => {
    vi.stubEnv('BFF_APP_ORIGIN', 'https://app.localhost');
    vi.stubEnv('NEXT_PUBLIC_APP_URL', 'https://other.localhost');

    expect(getBffAppOrigin()).toBe('https://app.localhost');
  });

  it('falls back to NEXT_PUBLIC_APP_URL when BFF_APP_ORIGIN is absent', () => {
    delete process.env.BFF_APP_ORIGIN;
    vi.stubEnv('NEXT_PUBLIC_APP_URL', 'https://app.localhost');

    expect(getBffAppOrigin()).toBe('https://app.localhost');
  });

  it('throws when both origins are absent', () => {
    delete process.env.BFF_APP_ORIGIN;
    delete process.env.NEXT_PUBLIC_APP_URL;

    expect(() => getBffAppOrigin()).toThrow('BFF_APP_ORIGIN or NEXT_PUBLIC_APP_URL must be set');
  });
});

describe('getCsrfHmacKey', () => {
  it('accepts base64 keys with at least 32 bytes', () => {
    const key = Buffer.alloc(32, 1).toString('base64');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', key);

    expect(getCsrfHmacKey()).toEqual(Buffer.alloc(32, 1));
  });

  it('accepts hex keys with at least 32 bytes', () => {
    const key = Buffer.alloc(32, 2).toString('hex');
    vi.stubEnv('BFF_CSRF_HMAC_KEY', key);

    expect(getCsrfHmacKey()).toEqual(Buffer.alloc(32, 2));
  });

  it('throws when key is missing', () => {
    delete process.env.BFF_CSRF_HMAC_KEY;

    expect(() => getCsrfHmacKey()).toThrow('BFF_CSRF_HMAC_KEY must be set');
  });

  it('throws when key is shorter than 32 bytes', () => {
    vi.stubEnv('BFF_CSRF_HMAC_KEY', Buffer.alloc(16, 3).toString('base64'));

    expect(() => getCsrfHmacKey()).toThrow('BFF_CSRF_HMAC_KEY must be at least 32 bytes');
  });
});

describe('getLaravelInternalUrl', () => {
  it('returns base URL without trailing slash', () => {
    vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1/');

    expect(getLaravelInternalUrl()).toBe('http://nginx/api/v1');
  });

  it('throws when LARAVEL_INTERNAL_URL is missing', () => {
    delete process.env.LARAVEL_INTERNAL_URL;

    expect(() => getLaravelInternalUrl()).toThrow('LARAVEL_INTERNAL_URL must be set');
  });
});
