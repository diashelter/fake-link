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

import { ResetPasswordForm } from './reset-password-form';

const server = setupServer();
const INITIAL_TOKEN = 'token-from-query';
const VALID_PASSWORD = 'Abcdefghij1!';
const RESET_SUCCESS_MESSAGE = 'Senha redefinida. Faça login para continuar.';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

async function fillValidForm(user: ReturnType<typeof userEvent.setup>, email = 'User@Example.COM') {
  await user.type(screen.getByLabelText(/^E-mail$/i), email);
  await user.type(screen.getByLabelText(/^Código de recuperação$/i), 'opaque-token');
  await user.type(screen.getByLabelText(/^Senha$/i), VALID_PASSWORD);
  await user.type(screen.getByLabelText(/Confirmar senha/i), VALID_PASSWORD);
}

describe('ResetPasswordForm (PW-09–11, PW-20, PW-22)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  it('does not fetch reset on mount with initialToken (PW-10 scanner-safe)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    window.history.pushState({}, '', `/reset-password?token=${INITIAL_TOKEN}`);

    render(<ResetPasswordForm initialToken={INITIAL_TOKEN} />);

    expect(screen.getByLabelText('Código de recuperação')).toHaveValue(INITIAL_TOKEN);
    await waitFor(() => {
      expect(fetchSpy).not.toHaveBeenCalled();
    });
    expect(document.body.innerHTML).not.toContain('Bearer');
    fetchSpy.mockRestore();
  });

  it('removes token query param via replaceState after mount (PW-09)', () => {
    window.history.pushState({}, '', `/reset-password?token=${INITIAL_TOKEN}`);
    const replaceSpy = vi.spyOn(window.history, 'replaceState');

    render(<ResetPasswordForm initialToken={INITIAL_TOKEN} />);

    expect(replaceSpy).toHaveBeenCalled();
    const urlArg = replaceSpy.mock.calls.at(-1)?.[2];
    expect(String(urlArg)).not.toMatch(/[?&]token=/);
    replaceSpy.mockRestore();
  });

  it('shows success message and navigates to /login on 200 with redirect_to (PW-09)', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/password/reset', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({
          data: {
            redirect_to: '/login',
            message: RESET_SUCCESS_MESSAGE,
          },
        });
      }),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toBe(RESET_SUCCESS_MESSAGE);
    });
    expect(pushMock).toHaveBeenCalledWith('/login');
    expect(capturedRequest).not.toBeNull();
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
    expect(capturedRequest!.credentials).toBe('include');
    await expect(capturedRequest!.json()).resolves.toEqual({
      email: 'user@example.com',
      token: 'opaque-token',
      password: VALID_PASSWORD,
      password_confirmation: VALID_PASSWORD,
    });
  });

  it('shows uniform token field error on 422 (PW-11)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json(
          {
            code: 'VALIDATION_FAILED',
            errors: {
              token: [{ code: 'INVALID', message: 'Invalid or expired token.' }],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Código de recuperação$/i)).toHaveAccessibleDescription(
        'Link de redefinição inválido ou expirado.',
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('shows PASSWORD_REUSED error on the password field (PW-11)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json(
          {
            code: 'VALIDATION_FAILED',
            errors: {
              password: [
                { code: 'PASSWORD_REUSED', message: 'The new password must be different.' },
              ],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha$/i)).toHaveAccessibleDescription(
        'A nova senha deve ser diferente da senha atual.',
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('shows account suspended message for 403 ACCOUNT_SUSPENDED (PW-21)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json({ code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' }, { status: 403 }),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe('Esta conta está suspensa.');
    });
  });

  it('shows pending deletion message for 403 ACCOUNT_PENDING_DELETION (PW-21)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json(
          { code: 'ACCOUNT_PENDING_DELETION', message: 'Pending deletion.' },
          { status: 403 },
        ),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Esta conta está em processo de exclusão.',
      );
    });
  });

  it('shows rate limit message with Retry-After guidance for 429 (PW-20)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('shows generic rate limit message when Retry-After is absent (PW-20)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset', () =>
        HttpResponse.json({ code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' }, { status: 429 }),
      ),
    );

    const user = setupUser();
    render(<ResetPasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Muitas tentativas. Aguarde antes de tentar novamente.',
      );
    });
  });

  it('renders a link back to login (PW-09)', () => {
    render(<ResetPasswordForm />);

    expect(screen.getByRole('link', { name: 'Voltar ao login' })).toHaveAttribute('href', '/login');
  });
});
