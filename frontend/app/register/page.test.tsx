import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';

vi.mock('server-only', () => ({}));

const redirectMock = vi.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
const ensurePreAuthCsrfCookiesMock = vi.fn();
const getSessionFromRequestMock = vi.fn();
const getAuthTermsCurrentVersionMock = vi.fn(() => '2026-01');

vi.mock('next/navigation', () => ({
  redirect: (url: string) => redirectMock(url),
}));

vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({
    get: vi.fn(() => undefined),
    set: vi.fn(),
  })),
  headers: vi.fn(async () => ({
    get: vi.fn(() => ''),
  })),
}));

vi.mock('@/modules/auth/bff/csrf', () => ({
  ensurePreAuthCsrfCookies: (...args: unknown[]) => ensurePreAuthCsrfCookiesMock(...args),
}));

vi.mock('@/modules/auth/services/bff-session', () => ({
  getSessionFromRequest: (...args: unknown[]) => getSessionFromRequestMock(...args),
}));

vi.mock('@/modules/auth/lib/auth-terms', () => ({
  getAuthTermsCurrentVersion: () => getAuthTermsCurrentVersionMock(),
}));

vi.mock('@/modules/auth/components/register-form', () => ({
  RegisterForm: ({ termsVersion }: { termsVersion: string }): ReactElement => (
    <div data-testid="register-form" data-terms-version={termsVersion} />
  ),
}));

import RegisterPage from './page';

describe('RegisterPage (RGR-13, RGR-14, BFFUI-41)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
    getAuthTermsCurrentVersionMock.mockClear();
    getAuthTermsCurrentVersionMock.mockReturnValue('2026-01');
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('redirects authenticated session users to /', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    await expect(RegisterPage()).rejects.toThrow('REDIRECT:/');
  });

  it('redirects verification session users to /verify-email', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(RegisterPage()).rejects.toThrow('REDIRECT:/verify-email');
  });

  it('bootstraps CSRF, passes termsVersion, and renders form for anonymous users', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await RegisterPage();
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
    expect(getAuthTermsCurrentVersionMock).toHaveBeenCalled();
    expect(serialized).toContain('"termsVersion":"2026-01"');
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain('bearer');
  });
});
