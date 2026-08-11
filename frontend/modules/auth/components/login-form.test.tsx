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

import { LoginForm } from './login-form';

const server = setupServer();

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

describe('LoginForm (LOG-05, LOG-06, LOG-08, LOG-10, LOG-13)', () => {
  it('blocks invalid submit without calling fetch', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = userEvent.setup();

    render(<LoginForm />);
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(screen.getByLabelText('E-mail')).toHaveAccessibleDescription(/e-mail/i);
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('navigates to redirect_to on 200', async () => {
    server.use(
      http.post('/api/bff/auth/login', () =>
        HttpResponse.json({ data: { redirect_to: '/dashboard', user: {} } }),
      ),
    );

    const user = userEvent.setup();
    render(<LoginForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.type(screen.getByLabelText('Senha'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/dashboard');
    });
  });

  it('shows anti-enum message for 401 INVALID_CREDENTIALS', async () => {
    server.use(
      http.post('/api/bff/auth/login', () =>
        HttpResponse.json(
          { code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' },
          { status: 401 },
        ),
      ),
    );

    const user = userEvent.setup();
    render(<LoginForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.type(screen.getByLabelText('Senha'), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe('E-mail ou senha incorretos.');
    });
  });

  it('shows account suspended message for 403', async () => {
    server.use(
      http.post('/api/bff/auth/login', () =>
        HttpResponse.json({ code: 'ACCOUNT_SUSPENDED', message: 'Suspended.' }, { status: 403 }),
      ),
    );

    const user = userEvent.setup();
    render(<LoginForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.type(screen.getByLabelText('Senha'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe('Esta conta está suspensa.');
    });
  });

  it('shows rate limit message with Retry-After guidance for 429', async () => {
    server.use(
      http.post('/api/bff/auth/login', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = userEvent.setup();
    render(<LoginForm />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.type(screen.getByLabelText('Senha'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('includes CSRF header and credentials on submit', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/login', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({ data: { redirect_to: '/', user: {} } });
      }),
    );

    const user = userEvent.setup();
    render(<LoginForm returnUrl="/safe" />);
    await user.type(screen.getByLabelText('E-mail'), 'user@example.com');
    await user.type(screen.getByLabelText('Senha'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    await waitFor(() => {
      expect(capturedRequest).not.toBeNull();
    });
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.url).toContain('returnUrl=%2Fsafe');
  });

  it('renders auxiliary navigation links', () => {
    render(<LoginForm />);

    expect(screen.getByRole('link', { name: 'Criar conta' })).toHaveAttribute('href', '/register');
    expect(screen.getByRole('link', { name: 'Esqueci minha senha' })).toHaveAttribute(
      'href',
      '/forgot-password',
    );
  });
});
