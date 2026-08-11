import { afterEach, describe, expect, it, vi } from 'vitest';

import { getAuthTermsCurrentVersion } from './auth-terms';

const ORIGINAL_ENV = { ...process.env };

afterEach(() => {
  process.env = { ...ORIGINAL_ENV };
  vi.unstubAllEnvs();
});

describe('getAuthTermsCurrentVersion (RGR-07, BFFUI-41)', () => {
  it('defaults to 2026-01 when env is absent', () => {
    delete process.env.NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION;

    expect(getAuthTermsCurrentVersion()).toBe('2026-01');
  });

  it('overrides via NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION', () => {
    vi.stubEnv('NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION', '2026-06');

    expect(getAuthTermsCurrentVersion()).toBe('2026-06');
  });
});
