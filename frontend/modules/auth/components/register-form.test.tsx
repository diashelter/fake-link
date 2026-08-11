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

import { RegisterForm } from './register-form';

const VALID_PASSWORD = 'Abcdefghij1!';
const TERMS_VERSION = '2026-01';
const REGISTRATION_NOT_ALLOWED_MESSAGE =
  'Não foi possível concluir o cadastro. Verifique seus dados ou entre em contato com o suporte.';

const server = setupServer();

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  pushMock.mockClear();
});
afterAll(() => server.close());

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/^Nome$/i), 'Ada Lovelace');
  await user.type(screen.getByLabelText(/^E-mail$/i), 'ada@example.com');
  await user.type(screen.getByLabelText(/^Senha$/i), VALID_PASSWORD);
  await user.type(screen.getByLabelText(/Confirmar senha/i), VALID_PASSWORD);
  await user.click(screen.getByRole('checkbox'));
}

describe('RegisterForm (RGR-05–09, RGR-11, RGR-13, BFFUI-41, BFFUI-32)', () => {
  it('blocks invalid submit without calling fetch', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = userEvent.setup();

    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Nome$/i)).toHaveAccessibleDescription(/nome/i);
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('blocks submit when Terms checkbox is unchecked without calling fetch', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = userEvent.setup();

    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await user.type(screen.getByLabelText(/^Nome$/i), 'Ada Lovelace');
    await user.type(screen.getByLabelText(/^E-mail$/i), 'ada@example.com');
    await user.type(screen.getByLabelText(/^Senha$/i), VALID_PASSWORD);
    await user.type(screen.getByLabelText(/Confirmar senha/i), VALID_PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(screen.getByText('Você precisa aceitar os Termos de uso.')).toBeInTheDocument();
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('navigates to /verify-email on 201', async () => {
    server.use(
      http.post('/api/bff/auth/register', () =>
        HttpResponse.json(
          { data: { redirect_to: '/verify-email', user: { status: 'pending_verification' } } },
          { status: 201 },
        ),
      ),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(pushMock).toHaveBeenCalledWith('/verify-email');
    });
  });

  it('shows identical REGISTRATION_NOT_ALLOWED message for invite-invalid and duplicate-email fixtures', async () => {
    const scenarios = [
      {
        label: 'invite-invalid',
        body: { code: 'REGISTRATION_NOT_ALLOWED', message: 'Invite invalid.' },
      },
      {
        label: 'duplicate-email',
        body: { code: 'REGISTRATION_NOT_ALLOWED', message: 'Email already registered.' },
      },
    ];

    for (const scenario of scenarios) {
      server.use(
        http.post('/api/bff/auth/register', () =>
          HttpResponse.json(scenario.body, { status: 403 }),
        ),
      );

      const user = userEvent.setup();
      render(<RegisterForm termsVersion={TERMS_VERSION} />);
      await fillValidForm(user);
      await user.click(screen.getByRole('button', { name: 'Criar conta' }));

      await waitFor(() => {
        expect(screen.getByRole('alert').textContent).toBe(REGISTRATION_NOT_ALLOWED_MESSAGE);
      });

      cleanup();
      server.resetHandlers();
    }
  });

  it('maps 422 server-side errors onto fields', async () => {
    server.use(
      http.post('/api/bff/auth/register', () =>
        HttpResponse.json(
          {
            code: 'VALIDATION_FAILED',
            message: 'The given data was invalid.',
            errors: {
              password: ['A senha deve conter um símbolo.'],
              email: ['O e-mail informado é inválido.'],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Senha$/i)).toHaveAccessibleDescription(
        /A senha deve conter um símbolo/,
      );
      expect(screen.getByLabelText(/^E-mail$/i)).toHaveAccessibleDescription(
        /O e-mail informado é inválido/,
      );
    });
  });

  it('shows rate limit message with Retry-After guidance for 429', async () => {
    server.use(
      http.post('/api/bff/auth/register', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('shows generic rate limit message when Retry-After is absent', async () => {
    server.use(
      http.post('/api/bff/auth/register', () =>
        HttpResponse.json({ code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' }, { status: 429 }),
      ),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Muitas tentativas. Aguarde antes de tentar novamente.',
      );
    });
  });

  it('includes CSRF header and credentials include on submit', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.post('/api/bff/auth/register', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json(
          { data: { redirect_to: '/verify-email', user: {} } },
          { status: 201 },
        );
      }),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(capturedRequest).not.toBeNull();
    });
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.credentials).toBe('include');
  });

  it('sends accept_terms true and RegisterRequest fields on submit', async () => {
    let capturedBody: Record<string, unknown> | null = null;
    server.use(
      http.post('/api/bff/auth/register', async ({ request }) => {
        capturedBody = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { data: { redirect_to: '/verify-email', user: {} } },
          { status: 201 },
        );
      }),
    );

    const user = userEvent.setup();
    render(<RegisterForm termsVersion={TERMS_VERSION} />);
    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => {
      expect(capturedBody).not.toBeNull();
    });
    expect(capturedBody).toEqual({
      name: 'Ada Lovelace',
      email: 'ada@example.com',
      password: VALID_PASSWORD,
      password_confirmation: VALID_PASSWORD,
      accept_terms: true,
    });
  });

  it('renders Terms version label, /terms link, and Já tenho conta link', () => {
    render(<RegisterForm termsVersion={TERMS_VERSION} />);

    expect(
      screen.getByRole('checkbox', {
        name: new RegExp(`Li e aceito os Termos de uso \\(versão ${TERMS_VERSION}\\)`, 'i'),
      }),
    ).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Termos de uso/i })).toHaveAttribute('href', '/terms');
    expect(screen.getByRole('link', { name: /Termos de uso/i })).toHaveAttribute('target', '_blank');
    expect(screen.getByRole('link', { name: 'Já tenho conta' })).toHaveAttribute('href', '/login');
  });
});
