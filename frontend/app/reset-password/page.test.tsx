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

vi.mock('@/modules/auth/components/reset-password-form', () => ({
  ResetPasswordForm: ({ initialToken }: { initialToken?: string }): ReactElement => (
    <div data-testid="reset-password-form" data-initial-token={initialToken ?? ''} />
  ),
}));

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import ResetPasswordPage from './page';

describe('ResetPasswordPage (PW-09, PW-10, PW-23)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('hydrates initialToken from the query and bootstraps CSRF (PW-09)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await ResetPasswordPage({
      searchParams: Promise.resolve({ token: 'from-query' }),
    });
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
    expect(serialized).toContain('"initialToken":"from-query"');
    expect(serialized).toContain('Redefinir senha');
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('bearer');
  });

  it('decodes a URL-encoded query token once before hydrating the form', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await ResetPasswordPage({
      searchParams: Promise.resolve({ token: 'hello%2Fworld' }),
    });

    expect(JSON.stringify(page)).toContain('"initialToken":"hello/world"');
  });

  it('renders the form without initialToken when the query token is absent (PW-09)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await ResetPasswordPage({
      searchParams: Promise.resolve({}),
    });
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
    expect(serialized).toContain('Redefinir senha');
    expect(serialized).not.toContain('"initialToken"');
  });

  it('keeps a malformed query token when decodeURIComponent throws', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await ResetPasswordPage({
      searchParams: Promise.resolve({ token: '%' }),
    });

    expect(JSON.stringify(page)).toContain('"initialToken":"%"');
  });

  it('does not fetch reset during render when ?token= is present (PW-10)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    getSessionFromRequestMock.mockResolvedValue(null);

    await ResetPasswordPage({
      searchParams: Promise.resolve({ token: 'from-query' }),
    });

    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('does not redirect verification sessions away from reset (PW-23)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    const page = await ResetPasswordPage({
      searchParams: Promise.resolve({ token: 'from-query' }),
    });

    expect(redirectMock).not.toHaveBeenCalled();
    expect(JSON.stringify(page)).toContain('"initialToken":"from-query"');
    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
  });
});
