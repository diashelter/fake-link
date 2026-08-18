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

import { VerifyEmailForm } from './verify-email-form';

const server = setupServer();
const INITIAL_TOKEN = 'token-from-query';
const RESEND_SUCCESS_MESSAGE =
  'Se o e-mail estiver cadastrado e pendente, você receberá um novo link.';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

describe('VerifyEmailForm (EV-08–15, BFFUI-51)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  it('does not fetch verify or resend on mount with initialToken (EV-15 scanner-safe)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    window.history.pushState({}, '', `/verify-email?token=${INITIAL_TOKEN}`);

    render(<VerifyEmailForm initialToken={INITIAL_TOKEN} />);

    expect(screen.getByLabelText('Código de verificação')).toHaveValue(INITIAL_TOKEN);
    await waitFor(() => {
      expect(fetchSpy).not.toHaveBeenCalled();
    });
    fetchSpy.mockRestore();
  });

  it('removes token query param via replaceState after mount (EV-12)', () => {
    window.history.pushState({}, '', `/verify-email?token=${INITIAL_TOKEN}`);
    const replaceSpy = vi.spyOn(window.history, 'replaceState');

    render(<VerifyEmailForm initialToken={INITIAL_TOKEN} />);

    expect(replaceSpy).toHaveBeenCalled();
    const urlArg = replaceSpy.mock.calls.at(-1)?.[2];
    expect(String(urlArg)).not.toMatch(/[?&]token=/);
    replaceSpy.mockRestore();
  });

  it('navigates to /login on 200 with redirect_to (EV-13)', async () => {
    server.use(
      http.post('/api/bff/auth/email/verify', () =>
        HttpResponse.json({
          data: {
            redirect_to: '/login',
            message: 'E-mail confirmado. Faça login para continuar.',
          },
        }),
      ),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), 'valid-token');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/login');
    });
  });

  it('shows resend confirmation on 202 (EV-14)', async () => {
    server.use(
      http.post('/api/bff/auth/email/resend', () =>
        HttpResponse.json({ message: 'Accepted.' }, { status: 202 }),
      ),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.click(screen.getByRole('button', { name: 'Reenviar e-mail' }));

    await waitFor(() => {
      expect(screen.getByText(RESEND_SUCCESS_MESSAGE)).toBeInTheDocument();
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('shows uniform message for 403 INVALID_VERIFICATION_TOKEN (EV-08)', async () => {
    server.use(
      http.post('/api/bff/auth/email/verify', () =>
        HttpResponse.json(
          { code: 'INVALID_VERIFICATION_TOKEN', message: 'Invalid token.' },
          { status: 403 },
        ),
      ),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), 'expired-token');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Link de verificação inválido ou expirado.',
      );
    });
    expect(pushMock).not.toHaveBeenCalled();
  });

  it('navigates to /login on 403 EMAIL_ALREADY_VERIFIED (EV-09)', async () => {
    server.use(
      http.post('/api/bff/auth/email/verify', () =>
        HttpResponse.json(
          { code: 'EMAIL_ALREADY_VERIFIED', message: 'Already verified.' },
          { status: 403 },
        ),
      ),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), 'already-used-token');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/login');
    });
  });

  it('shows rate limit message with Retry-After guidance for 429 (EV-10)', async () => {
    server.use(
      http.post('/api/bff/auth/email/verify', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), 'valid-token');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('blocks empty token submit without calling fetch (EV-18)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();

    render(<VerifyEmailForm />);
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(screen.getByText('Informe o código de verificação.')).toBeInTheDocument();
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('rejects whitespace-only token without calling fetch (EV-18)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();

    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), '   ');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(screen.getByText('Informe o código de verificação.')).toBeInTheDocument();
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('renders a link to login (EV-12)', () => {
    render(<VerifyEmailForm />);

    expect(screen.getByRole('link', { name: 'Ir para login' })).toHaveAttribute('href', '/login');
  });

  it('sends CSRF header and token body on verify submit (EV-13)', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/email/verify', async ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({ data: { redirect_to: '/login' } });
      }),
    );

    const user = setupUser();
    render(<VerifyEmailForm />);
    await user.type(screen.getByLabelText('Código de verificação'), 'opaque-token');
    await user.click(screen.getByRole('button', { name: 'Confirmar e-mail' }));

    await waitFor(() => {
      expect(capturedRequest).not.toBeNull();
    });
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
    await expect(capturedRequest!.json()).resolves.toEqual({ token: 'opaque-token' });
  });
});
