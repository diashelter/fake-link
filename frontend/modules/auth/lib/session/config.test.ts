import { afterEach, describe, expect, it } from 'vitest';

import { loadBffSessionConfig } from './config';

const ENV_KEYS = [
  'BFF_SESSION_AES_KEY',
  'BFF_SESSION_HMAC_KEY',
  'BFF_SESSION_COOKIE_NAME',
  'BFF_SESSION_AES_KEY_ID',
  'BFF_SESSION_PROBE_ENABLED',
  'REDIS_HOST',
  'REDIS_PORT',
] as const;

const VALID_AES_KEY = Buffer.alloc(32, 7).toString('base64');
const VALID_HMAC_KEY = Buffer.alloc(32, 9).toString('base64');

function clearEnv(): void {
  for (const key of ENV_KEYS) {
    delete process.env[key];
  }
}

function setValidEnv(overrides: Partial<Record<(typeof ENV_KEYS)[number], string>> = {}): void {
  process.env.BFF_SESSION_AES_KEY = VALID_AES_KEY;
  process.env.BFF_SESSION_HMAC_KEY = VALID_HMAC_KEY;
  process.env.REDIS_HOST = 'redis-ephemeral';
  process.env.REDIS_PORT = '6379';
  Object.assign(process.env, overrides);
}

describe('loadBffSessionConfig (SC-14)', () => {
  afterEach(() => {
    clearEnv();
  });

  it('loads valid AES (32 bytes) and HMAC (≥32 bytes) keys with defaults', () => {
    setValidEnv();

    const config = loadBffSessionConfig();

    expect(config.aesKey).toEqual(Buffer.from(VALID_AES_KEY, 'base64'));
    expect(config.aesKey.length).toBe(32);
    expect(config.hmacKey).toEqual(Buffer.from(VALID_HMAC_KEY, 'base64'));
    expect(config.hmacKey.length).toBeGreaterThanOrEqual(32);
    expect(config.aesKeyId).toBe('1');
    expect(config.cookieName).toBe('__Host-fl_session');
    expect(config.redisUrl).toBe('redis://redis-ephemeral:6379');
    expect(config.probeEnabled).toBe(false);
  });

  it('accepts HMAC keys longer than 32 bytes', () => {
    const longHmac = Buffer.alloc(48, 3).toString('base64');
    setValidEnv({ BFF_SESSION_HMAC_KEY: longHmac });

    const config = loadBffSessionConfig();

    expect(config.hmacKey.length).toBe(48);
  });

  it('applies cookie name, kid, and probe overrides from env', () => {
    setValidEnv({
      BFF_SESSION_COOKIE_NAME: '__Host-custom',
      BFF_SESSION_AES_KEY_ID: '2',
      BFF_SESSION_PROBE_ENABLED: 'true',
    });

    const config = loadBffSessionConfig();

    expect(config.cookieName).toBe('__Host-custom');
    expect(config.aesKeyId).toBe('2');
    expect(config.probeEnabled).toBe(true);
  });

  it('throws an explicit error when BFF_SESSION_AES_KEY is missing', () => {
    setValidEnv();
    delete process.env.BFF_SESSION_AES_KEY;

    expect(() => loadBffSessionConfig()).toThrow(/BFF_SESSION_AES_KEY/);
  });

  it('throws an explicit error when BFF_SESSION_HMAC_KEY is missing', () => {
    setValidEnv();
    delete process.env.BFF_SESSION_HMAC_KEY;

    expect(() => loadBffSessionConfig()).toThrow(/BFF_SESSION_HMAC_KEY/);
  });

  it('throws an explicit error when REDIS_HOST is missing', () => {
    setValidEnv();
    delete process.env.REDIS_HOST;

    expect(() => loadBffSessionConfig()).toThrow(/REDIS_HOST/);
  });

  it('throws an explicit error when REDIS_PORT is missing', () => {
    setValidEnv();
    delete process.env.REDIS_PORT;

    expect(() => loadBffSessionConfig()).toThrow(/REDIS_PORT/);
  });

  it('throws when AES key does not decode to exactly 32 bytes', () => {
    setValidEnv({ BFF_SESSION_AES_KEY: Buffer.alloc(16, 1).toString('base64') });

    expect(() => loadBffSessionConfig()).toThrow(/BFF_SESSION_AES_KEY/);
  });

  it('throws when HMAC key decodes to fewer than 32 bytes', () => {
    setValidEnv({ BFF_SESSION_HMAC_KEY: Buffer.alloc(16, 2).toString('base64') });

    expect(() => loadBffSessionConfig()).toThrow(/BFF_SESSION_HMAC_KEY/);
  });

  it('throws when AES key is malformed base64 that yields wrong length', () => {
    setValidEnv({ BFF_SESSION_AES_KEY: 'not-valid-base64!!!' });

    expect(() => loadBffSessionConfig()).toThrow(/BFF_SESSION_AES_KEY/);
  });
});
