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

import { ChangePasswordForm } from './change-password-form';

const server = setupServer();
const VALID_PASSWORD = 'Abcdefghij1!';
const CURRENT_PASSWORD = 'old-secret';
const CHANGE_SUCCESS_MESSAGE = 'Senha alterada. Faça login para continuar.';
const CURRENT_PASSWORD_INVALID_MESSAGE = 'Senha atual incorreta.';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/^Senha atual$/i), CURRENT_PASSWORD);
  await user.type(screen.getByLabelText(/^Nova senha$/i), VALID_PASSWORD);
  await user.type(screen.getByLabelText(/Confirmar nova senha/i), VALID_PASSWORD);
}

describe('ChangePasswordForm (PW-16, PW-17, PW-20, PW-22)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  it('shows success message and navigates to /login on 200 (PW-16)', async () => {
    server.use(
      http.post('/api/bff/auth/password/change', () =>
        HttpResponse.json({
          data: {
            redirect_to: '/login',
            message: CHANGE_SUCCESS_MESSAGE,
          },
        }),
      ),
    );

    const user = setupUser();
    render(<ChangePasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toBe(CHANGE_SUCCESS_MESSAGE);
    });
    expect(pushMock).toHaveBeenCalledWith('/login');
    expect(document.body.innerHTML).not.toContain('Bearer');
  });

  it('shows INVALID_CREDENTIALS error on current_password (PW-17)', async () => {
    server.use(
      http.post('/api/bff/auth/password/change', () =>
        HttpResponse.json(
          { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' },
          { status: 401 },
        ),
      ),
    );

    const user = setupUser();
    render(<ChangePasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha atual$/i)).toHaveAccessibleDescription(
        CURRENT_PASSWORD_INVALID_MESSAGE,
      );
    });
    expect(screen.queryByText('E-mail ou senha incorretos.')).not.toBeInTheDocument();
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('shows PASSWORD_REUSED error on the password field (PW-17)', async () => {
    server.use(
      http.post('/api/bff/auth/password/change', () =>
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
    render(<ChangePasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Nova senha$/i)).toHaveAccessibleDescription(
        'A nova senha deve ser diferente da senha atual.',
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('blocks invalid client submit without calling fetch (PW-16)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();

    render(<ChangePasswordForm />);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha atual$/i)).toHaveAccessibleDescription(
        'Informe sua senha atual.',
      );
    });
    expect(fetchSpy).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(/^Senha atual$/i), CURRENT_PASSWORD);
    await user.type(screen.getByLabelText(/^Nova senha$/i), 'short');
    await user.type(screen.getByLabelText(/Confirmar nova senha/i), 'short');
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Nova senha$/i)).toHaveAccessibleDescription(
        /pelo menos 12 caracteres/i,
      );
    });
    expect(fetchSpy).not.toHaveBeenCalled();

    await user.clear(screen.getByLabelText(/^Nova senha$/i));
    await user.clear(screen.getByLabelText(/Confirmar nova senha/i));
    await user.type(screen.getByLabelText(/^Nova senha$/i), VALID_PASSWORD);
    await user.type(screen.getByLabelText(/Confirmar nova senha/i), `${VALID_PASSWORD}x`);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/Confirmar nova senha/i)).toHaveAccessibleDescription(
        'As senhas não coincidem.',
      );
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('shows rate limit message with Retry-After guidance for 429 (PW-20)', async () => {
    server.use(
      http.post('/api/bff/auth/password/change', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    render(<ChangePasswordForm />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });
});
