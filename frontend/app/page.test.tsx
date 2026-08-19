import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

const redirectMock = vi.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
const getSessionFromRequestMock = vi.fn();

vi.mock('next/navigation', () => ({
  redirect: (url: string) => redirectMock(url),
  useRouter: () => ({ push: vi.fn() }),
}));

vi.mock('next/headers', () => ({
  headers: vi.fn(async () => ({
    get: vi.fn(() => ''),
  })),
}));

vi.mock('@/modules/auth/lib/client-cookie', () => ({
  readClientCookie: vi.fn(() => 'test-csrf-token'),
}));

vi.mock('@/modules/auth/services/bff-session', () => ({
  getSessionFromRequest: (...args: unknown[]) => getSessionFromRequestMock(...args),
}));

import HomePage from './page';

describe('HomePage verification guard (EV-16) and session shell (SH-19, BFFUI-74)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('redirects verification sessions to /verify-email', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'verification',
      userId: 'uid',
    });

    await expect(HomePage()).rejects.toThrow('REDIRECT:/verify-email');
  });

  it('renders the landing for anonymous visitors', async () => {
    getSessionFromRequestMock.mockResolvedValue(null);

    const page = await HomePage();
    const serialized = JSON.stringify(page);

    expect(redirectMock).not.toHaveBeenCalled();
    expect(serialized).toContain('Fake Link');
    expect(serialized).toContain('Plataforma de encurtamento de URLs.');
    expect(serialized).toContain('Começar');

    render(page);
    expect(screen.getByRole('link', { name: 'Começar' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Conta' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Sair' })).toBeNull();
  });

  it('renders authenticated shell for session users instead of the guest landing (SH-19)', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    const page = await HomePage();

    expect(redirectMock).not.toHaveBeenCalled();

    render(page);

    expect(screen.getByRole('link', { name: 'Início' }).getAttribute('href')).toBe('/');
    expect(screen.getByRole('link', { name: 'Conta' }).getAttribute('href')).toBe('/settings');
    expect(screen.getByRole('button', { name: 'Sair' })).toBeTruthy();
    expect(screen.getByText(/em breve/i)).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Começar' })).toBeNull();
  });
});
