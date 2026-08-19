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

vi.mock('@/modules/auth/components/change-password-form', () => ({
  ChangePasswordForm: (): ReactElement => <div data-testid="change-password-form" />,
}));

import { FIXTURE_BEARER } from '@/modules/auth/lib/test/auth-fixtures';

import SettingsPasswordPage from './page';

describe('SettingsPasswordPage (PW-15)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    ensurePreAuthCsrfCookiesMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('redirects anonymous visitors to /login (PW-15)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    await expect(SettingsPasswordPage()).rejects.toThrow('REDIRECT:/login');
  });

  it('redirects verification session users to /verify-email (PW-15)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(SettingsPasswordPage()).rejects.toThrow('REDIRECT:/verify-email');
  });

  it('renders the change password form for session kind without pre-auth CSRF (PW-15)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    const page = await SettingsPasswordPage();
    const serialized = JSON.stringify(page);

    expect(ensurePreAuthCsrfCookiesMock).not.toHaveBeenCalled();
    expect(redirectMock).not.toHaveBeenCalled();
    expect(serialized).toContain('Alterar senha');
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('bearer');

    render(page);
    expect(screen.getByTestId('change-password-form')).toBeTruthy();
  });
});
