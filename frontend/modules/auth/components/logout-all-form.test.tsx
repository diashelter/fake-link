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

import { LogoutAllForm } from './logout-all-form';

const server = setupServer();
const CURRENT_PASSWORD = 'sentinel-logout-all-password-xyz';
const CURRENT_PASSWORD_INVALID_MESSAGE = 'Senha atual incorreta.';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

describe('LogoutAllForm (SH-17, SH-22, SH-23, L-046, L-053)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  async function fillPassword(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/^Senha atual$/i), CURRENT_PASSWORD);
  }

  it('posts current_password with Content-Type and CSRF and navigates to /login on 200 (SH-17)', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/logout-all', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({
          data: {
            redirect_to: '/login',
            message: 'Todas as sessões foram encerradas. Faça login para continuar.',
          },
        });
      }),
    );

    const user = setupUser();
    render(<LogoutAllForm />);
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/login');
    });
    expect(pushMock).toHaveBeenCalledTimes(1);
    expect(pushMock.mock.calls[0]?.[0]).not.toContain('?message=');
    expect(document.body.innerHTML).not.toContain(CURRENT_PASSWORD);
    expect(capturedRequest).not.toBeNull();
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
    expect(capturedRequest!.credentials).toBe('include');
    await expect(capturedRequest!.json()).resolves.toEqual({
      current_password: CURRENT_PASSWORD,
    });
  });

  it('shows INVALID_CREDENTIALS error on current_password and does not navigate (SH-17)', async () => {
    server.use(
      http.post('/api/bff/auth/logout-all', () =>
        HttpResponse.json(
          { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' },
          { status: 401 },
        ),
      ),
    );

    const user = setupUser();
    render(<LogoutAllForm />);
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha atual$/i)).toHaveAccessibleDescription(
        CURRENT_PASSWORD_INVALID_MESSAGE,
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
    expect(document.body.innerHTML).not.toContain(CURRENT_PASSWORD);
  });

  it('maps 422 field errors onto current_password (SH-22)', async () => {
    server.use(
      http.post('/api/bff/auth/logout-all', () =>
        HttpResponse.json(
          {
            code: 'VALIDATION_FAILED',
            errors: {
              current_password: [{ message: 'Informe sua senha atual.' }],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = setupUser();
    render(<LogoutAllForm />);
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha atual$/i)).toHaveAccessibleDescription(
        'Informe sua senha atual.',
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('shows rate limit message with Retry-After guidance for 429 (SH-23)', async () => {
    server.use(
      http.post('/api/bff/auth/logout-all', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    render(<LogoutAllForm />);
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('shows generic rate limit message when Retry-After is absent (SH-23)', async () => {
    server.use(
      http.post('/api/bff/auth/logout-all', () =>
        HttpResponse.json({ code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' }, { status: 429 }),
      ),
    );

    const user = setupUser();
    render(<LogoutAllForm />);
    await fillPassword(user);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Muitas tentativas. Aguarde antes de tentar novamente.',
      );
    });
  });

  it('blocks empty submit without calling fetch', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();

    render(<LogoutAllForm />);
    await user.click(screen.getByRole('button', { name: /Encerrar todas as sessões/i }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha atual$/i)).toHaveAccessibleDescription(
        'Informe sua senha atual.',
      );
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });
});
