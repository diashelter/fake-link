import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

const redirectMock = vi.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
const getSessionFromRequestMock = vi.fn();

vi.mock('next/navigation', () => ({
  redirect: (url: string) => redirectMock(url),
}));

vi.mock('next/headers', () => ({
  headers: vi.fn(async () => ({
    get: vi.fn(() => ''),
  })),
}));

vi.mock('@/modules/auth/services/bff-session', () => ({
  getSessionFromRequest: (...args: unknown[]) => getSessionFromRequestMock(...args),
}));

import HomePage from './page';

describe('HomePage verification guard (EV-16)', () => {
  beforeEach(() => {
    redirectMock.mockClear();
    getSessionFromRequestMock.mockReset();
  });

  afterEach(() => {
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
  });

  it('renders the landing for authenticated session users', async () => {
    getSessionFromRequestMock.mockResolvedValue({
      sessionId: 'sid',
      kind: 'session',
      userId: 'uid',
    });

    const page = await HomePage();
    const serialized = JSON.stringify(page);

    expect(redirectMock).not.toHaveBeenCalled();
    expect(serialized).toContain('Fake Link');
    expect(serialized).toContain('Plataforma de encurtamento de URLs.');
  });
});
