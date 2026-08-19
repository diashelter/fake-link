import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

vi.mock('@/modules/auth/lib/client-cookie', () => ({
  readClientCookie: vi.fn(() => 'test-csrf-token'),
}));

import { FORGOT_PASSWORD_SUCCESS_MESSAGE, ForgotPasswordForm } from './forgot-password-form';

const server = setupServer();

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
});
afterAll(() => server.close());

describe('ForgotPasswordForm (PW-04, PW-05, PW-20)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  it('shows the same success copy for two different emails on 202 (PW-05)', async () => {
    const emails = ['User@Example.COM', 'nobody@example.com'];

    for (const email of emails) {
      let capturedRequest: Request | null = null;
      server.use(
        http.post('/api/bff/auth/password/reset-request', ({ request }) => {
          capturedRequest = request;
          return HttpResponse.json({ message: 'Accepted.' }, { status: 202 });
        }),
      );

      const user = setupUser();
      const { container } = render(<ForgotPasswordForm />);
      await user.type(screen.getByLabelText('E-mail'), email);
      await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

      await waitFor(() => {
        expect(screen.getByRole('status').textContent).toBe(FORGOT_PASSWORD_SUCCESS_MESSAGE);
      });
      expect(capturedRequest).not.toBeNull();
      expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
      expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
      expect(capturedRequest!.headers.get('Authorization')).toBeNull();
      const body = await capturedRequest!.clone().json();
      expect(body).toEqual({ email: email.toLowerCase() });
      expect(container.innerHTML).not.toContain('Bearer');
      expect(JSON.stringify(body)).not.toContain('Bearer');

      cleanup();
      server.resetHandlers();
    }
  });

  it('blocks invalid email submit without calling fetch (PW-04, PW-18)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();

    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('E-mail'), 'not-an-email');
    await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

    await waitFor(() => {
      expect(screen.getByLabelText('E-mail')).toHaveAccessibleDescription(/e-mail/i);
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('shows account suspended message for 403 ACCOUNT_SUSPENDED (PW-21)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset-request', () =>
        HttpResponse.json({ code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' }, { status: 403 }),
      ),
    );

    const user = setupUser();
    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe('Esta conta está suspensa.');
    });
  });

  it('shows pending deletion message for 403 ACCOUNT_PENDING_DELETION (PW-21)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset-request', () =>
        HttpResponse.json(
          { code: 'ACCOUNT_PENDING_DELETION', message: 'Pending deletion.' },
          { status: 403 },
        ),
      ),
    );

    const user = setupUser();
    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Esta conta está em processo de exclusão.',
      );
    });
  });

  it('shows rate limit message with Retry-After guidance for 429 (PW-20)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset-request', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('shows generic rate limit message when Retry-After is absent (PW-20)', async () => {
    server.use(
      http.post('/api/bff/auth/password/reset-request', () =>
        HttpResponse.json({ code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' }, { status: 429 }),
      ),
    );

    const user = setupUser();
    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar instruções' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Muitas tentativas. Aguarde antes de tentar novamente.',
      );
    });
  });

  it('renders a link back to login (PW-04)', () => {
    render(<ForgotPasswordForm />);

    expect(screen.getByRole('link', { name: 'Voltar ao login' })).toHaveAttribute('href', '/login');
    expect(document.body.innerHTML).not.toContain('Bearer');
  });
});
