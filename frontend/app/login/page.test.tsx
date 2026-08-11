import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';

vi.mock('server-only', () => ({}));

const redirectMock = vi.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
const ensurePreAuthCsrfCookiesMock = vi.fn();
const getSessionFromRequestMock = vi.fn();

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

vi.mock('@/modules/auth/components/login-form', () => ({
  LoginForm: ({ returnUrl }: { returnUrl?: string }): ReactElement => (
    <div data-testid="login-form" data-return-url={returnUrl ?? ''} />
  ),
}));

import LoginPage from './page';

describe('LoginPage (LOG-11, LOG-10)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('redirects authenticated session users to / when returnUrl is absent', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    await expect(LoginPage({ searchParams: Promise.resolve({}) })).rejects.toThrow('REDIRECT:/');
  });

  it('redirects authenticated session users to safe returnUrl', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    await expect(
      LoginPage({ searchParams: Promise.resolve({ returnUrl: '/links' }) }),
    ).rejects.toThrow('REDIRECT:/links');
  });

  it('redirects verification session users to /verify-email', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(LoginPage({ searchParams: Promise.resolve({}) })).rejects.toThrow(
      'REDIRECT:/verify-email',
    );
  });

  it('bootstraps CSRF and renders login form for anonymous users', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await LoginPage({ searchParams: Promise.resolve({ returnUrl: '/safe' }) });

    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
    expect(JSON.stringify(page)).not.toContain('Bearer');
    expect(JSON.stringify(page)).not.toContain('bearer');
  });
});
