import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement, ReactNode } from 'react';

vi.mock('server-only', () => ({}));

const redirectMock = vi.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
const getSessionFromRequestMock = vi.fn();
const destroySessionMock = vi.fn();
const performBffMeGetMock = vi.fn();
const cookiesSetMock = vi.fn();

vi.mock('next/navigation', () => ({
  redirect: (url: string) => redirectMock(url),
}));

vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({
    get: vi.fn(() => undefined),
    set: cookiesSetMock,
  })),
  headers: vi.fn(async () => ({
    get: vi.fn(() => ''),
  })),
}));

vi.mock('@/modules/auth/services/bff-session', () => ({
  getSessionFromRequest: (...args: unknown[]) => getSessionFromRequestMock(...args),
  destroySession: (...args: unknown[]) => destroySessionMock(...args),
}));

vi.mock('@/modules/auth/services/bff-me', () => ({
  performBffMeGet: (...args: unknown[]) => performBffMeGetMock(...args),
}));

vi.mock('@/modules/auth/components/authenticated-shell', () => ({
  AuthenticatedShell: ({ children }: { children: ReactNode }): ReactElement => (
    <div data-testid="authenticated-shell">{children}</div>
  ),
}));

vi.mock('@/modules/auth/components/profile-form', () => ({
  ProfileForm: ({ name, email }: { name: string; email: string }): ReactElement => (
    <div data-testid="profile-form" data-name={name} data-email={email} />
  ),
}));

vi.mock('@/modules/auth/components/logout-all-form', () => ({
  LogoutAllForm: (): ReactElement => <div data-testid="logout-all-form" />,
}));

import { FIXTURE_BEARER, FIXTURE_USER } from '@/modules/auth/lib/test/auth-fixtures';

import SettingsLayout from './layout';
import SettingsPage from './page';

const SESSION_USER = {
  sessionId: 'sid',
  kind: 'session' as const,
  userId: 'uid',
};

function jsonResponse(status: number, body: unknown) {
  return {
    ok: status === 200,
    response: {
      status,
      json: async () => body,
    },
  };
}

describe('Settings layout and profile page (SH-14, SH-18, SH-20, BFFUI-73, BFFUI-74)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    getSessionFromRequestMock.mockReset();
    destroySessionMock.mockReset();
    destroySessionMock.mockResolvedValue({ clearCookie: true });
    performBffMeGetMock.mockReset();
    cookiesSetMock.mockClear();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('layout redirects guests to /login (SH-18)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    await expect(SettingsLayout({ children: <div /> })).rejects.toThrow('REDIRECT:/login');
    expect(performBffMeGetMock).not.toHaveBeenCalled();
  });

  it('page redirects guests to /login (SH-18)', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    await expect(SettingsPage()).rejects.toThrow('REDIRECT:/login');
    expect(performBffMeGetMock).not.toHaveBeenCalled();
  });

  it('layout redirects verification sessions to /verify-email (SH-19)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(SettingsLayout({ children: <div /> })).rejects.toThrow('REDIRECT:/verify-email');
  });

  it('page redirects verification sessions to /verify-email (SH-19)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(SettingsPage()).rejects.toThrow('REDIRECT:/verify-email');
    expect(performBffMeGetMock).not.toHaveBeenCalled();
  });

  it('layout wraps session users with AuthenticatedShell', async () => {
    getSessionFromRequestMock.mockResolvedValue(SESSION_USER);

    const layout = await SettingsLayout({ children: <p>conteúdo da conta</p> });
    render(layout);

    expect(redirectMock).not.toHaveBeenCalled();
    expect(screen.getByTestId('authenticated-shell')).toBeTruthy();
    expect(screen.getByText('conteúdo da conta')).toBeTruthy();
  });

  it('renders profile, logout-all, and password link for session users (SH-14)', async () => {
    getSessionFromRequestMock.mockResolvedValue(SESSION_USER);
    performBffMeGetMock.mockResolvedValue(
      jsonResponse(200, { data: { name: FIXTURE_USER.name, email: FIXTURE_USER.email } }),
    );

    const page = await SettingsPage();
    const serialized = JSON.stringify(page);

    expect(redirectMock).not.toHaveBeenCalled();
    expect(serialized).not.toContain('Bearer');
    expect(serialized).not.toContain(FIXTURE_BEARER);
    expect(serialized).not.toContain('bearer');

    render(page);

    const profile = screen.getByTestId('profile-form');
    expect(profile.getAttribute('data-name')).toBe(FIXTURE_USER.name);
    expect(profile.getAttribute('data-email')).toBe(FIXTURE_USER.email);
    expect(screen.getByTestId('logout-all-form')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Alterar senha' }).getAttribute('href')).toBe(
      '/settings/password',
    );
  });

  it.each(['ACCOUNT_SUSPENDED', 'ACCOUNT_PENDING_DELETION'] as const)(
    'destroys session, expires cookies, and redirects to /login on GET me 403 %s',
    async (code) => {
      getSessionFromRequestMock.mockResolvedValue(SESSION_USER);
      performBffMeGetMock.mockResolvedValue(
        jsonResponse(403, { message: 'Forbidden.', code }),
      );

      await expect(SettingsPage()).rejects.toThrow('REDIRECT:/login');

      expect(destroySessionMock).toHaveBeenCalledWith('sid');
      expect(cookiesSetMock).toHaveBeenCalledWith(
        '__Host-fl_session',
        '',
        expect.objectContaining({ maxAge: 0, httpOnly: true, secure: true, sameSite: 'lax', path: '/' }),
      );
      expect(cookiesSetMock).toHaveBeenCalledWith(
        '__Host-fl_csrf',
        '',
        expect.objectContaining({ maxAge: 0, secure: true, path: '/' }),
      );
      expect(cookiesSetMock).toHaveBeenCalledWith(
        '__Host-fl_csrf_sid',
        '',
        expect.objectContaining({ maxAge: 0, httpOnly: true, secure: true, sameSite: 'lax', path: '/' }),
      );
      expect(redirectMock).toHaveBeenCalledWith('/login');
    },
  );

  it('still expires cookies and redirects to /login when destroySession fails (ACCOUNT_SUSPENDED)', async () => {
    getSessionFromRequestMock.mockResolvedValue(SESSION_USER);
    destroySessionMock.mockRejectedValue(new Error('redis down'));
    performBffMeGetMock.mockResolvedValue(
      jsonResponse(403, { message: 'Forbidden.', code: 'ACCOUNT_SUSPENDED' }),
    );

    await expect(SettingsPage()).rejects.toThrow('REDIRECT:/login');

    expect(destroySessionMock).toHaveBeenCalledWith('sid');
    expect(cookiesSetMock).toHaveBeenCalledWith(
      '__Host-fl_session',
      '',
      expect.objectContaining({ maxAge: 0 }),
    );
    expect(redirectMock).toHaveBeenCalledWith('/login');
  });
});
