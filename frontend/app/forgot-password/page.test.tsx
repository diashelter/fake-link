import { render, screen } from '@testing-library/react';
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

vi.mock('@/modules/auth/components/forgot-password-form', () => ({
  ForgotPasswordForm: (): ReactElement => <div data-testid="forgot-password-form" />,
}));

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import ForgotPasswordPage from './page';

describe('ForgotPasswordPage (PW-04, PW-23)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('bootstraps CSRF and renders the form for anonymous visitors (PW-04)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await ForgotPasswordPage();
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
    expect(redirectMock).not.toHaveBeenCalled();
    expect(serialized).toContain('Recuperar senha');
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('bearer');

    render(page);
    expect(screen.getByTestId('forgot-password-form')).toBeTruthy();
  });

  it('does not redirect verification sessions away from recovery (PW-23)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    const page = await ForgotPasswordPage();

    expect(redirectMock).not.toHaveBeenCalled();
    expect(JSON.stringify(page)).toContain('Recuperar senha');
    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
  });

  it('does not redirect authenticated session users away from recovery (PW-23)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    const page = await ForgotPasswordPage();

    expect(redirectMock).not.toHaveBeenCalled();
    expect(JSON.stringify(page)).toContain('Recuperar senha');
    expect(ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce();
  });
});
