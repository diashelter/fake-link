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

vi.mock('@/modules/auth/components/verify-email-form', () => ({
  VerifyEmailForm: ({ initialToken }: { initialToken?: string }): ReactElement => (
    <div data-testid="verify-email-form" initialToken={initialToken} />
  ),
}));

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import VerifyEmailPage from './page';

describe('VerifyEmailPage (EV-12, EV-15)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('redirects anonymous visitors to /login (EV-12)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    await expect(VerifyEmailPage({ searchParams: Promise.resolve({}) })).rejects.toThrow(
      'REDIRECT:/login',
    );
  });

  it('redirects authenticated session users to / (EV-12)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    await expect(VerifyEmailPage({ searchParams: Promise.resolve({}) })).rejects.toThrow(
      'REDIRECT:/',
    );
  });

  it('renders the form with query token for verification sessions (EV-12)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    const page = await VerifyEmailPage({
      searchParams: Promise.resolve({ token: 'from-query' }),
    });
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).not.toHaveBeenCalled();
    expect(serialized).toContain('"initialToken":"from-query"');
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('bearer');
  });

  it('does not fetch verify or resend during render (EV-15)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await VerifyEmailPage({ searchParams: Promise.resolve({ token: 'from-query' }) });

    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });
});
