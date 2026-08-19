import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const pushMock = vi.fn();

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: pushMock }),
}));

vi.mock('@/modules/auth/lib/client-cookie', () => ({
  readClientCookie: vi.fn(() => 'test-csrf-token'),
}));

import { LogoutButton } from './logout-button';

const server = setupServer();
const FORBIDDEN_MESSAGE = 'Você não tem permissão para concluir esta ação.';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

describe('LogoutButton (SH-16, SH-23, L-046)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  it('posts empty JSON with Content-Type and CSRF and navigates to /login on 200 (SH-16)', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/logout', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({
          data: {
            redirect_to: '/login',
            message: 'Você saiu da conta.',
          },
        });
      }),
    );

    const user = setupUser();
    render(<LogoutButton />);
    await user.click(screen.getByRole('button', { name: 'Sair' }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/login');
    });
    expect(pushMock).toHaveBeenCalledTimes(1);
    expect(pushMock.mock.calls[0]?.[0]).not.toContain('?message=');
    expect(capturedRequest).not.toBeNull();
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
    expect(capturedRequest!.credentials).toBe('include');
    await expect(capturedRequest!.json()).resolves.toEqual({});
  });

  it('shows generic pt-BR permission copy on 403 without CSRF or Origin (SH-23)', async () => {
    server.use(
      http.post('/api/bff/auth/logout', () =>
        HttpResponse.json({ message: 'Forbidden.' }, { status: 403 }),
      ),
    );

    const user = setupUser();
    render(<LogoutButton />);
    await user.click(screen.getByRole('button', { name: 'Sair' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(FORBIDDEN_MESSAGE);
    });
    expect(screen.queryByText('Forbidden.')).not.toBeInTheDocument();
    expect(document.body.innerHTML).not.toMatch(/CSRF/);
    expect(document.body.innerHTML).not.toMatch(/Origin/);
    expect(pushMock).not.toHaveBeenCalled();
  });
});
