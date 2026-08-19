import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@/modules/auth/lib/client-cookie', () => ({
  readClientCookie: vi.fn(() => 'test-csrf-token'),
}));

import { ProfileForm } from './profile-form';

const server = setupServer();
const INITIAL_NAME = 'Ana Costa';
const INITIAL_EMAIL = 'ana@example.com';
const UPDATED_NAME = 'Ana Silva';

function enabledTextboxes(): HTMLElement[] {
  return screen.getAllByRole('textbox').filter((element) => {
    const input = element as HTMLInputElement;
    return !input.readOnly && !input.disabled;
  });
}

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
});
afterAll(() => server.close());

describe('ProfileForm (SH-14, SH-15, SH-22, SH-23, L-046, L-053)', () => {
  function setupUser() {
    return userEvent.setup({ delay: null });
  }

  function renderForm() {
    render(<ProfileForm name={INITIAL_NAME} email={INITIAL_EMAIL} />);
  }

  it('renders name as the only enabled textbox and email as non-editable (SH-14)', () => {
    renderForm();

    const nameInput = screen.getByLabelText(/^Nome$/i);
    expect(nameInput).toHaveValue(INITIAL_NAME);
    expect(enabledTextboxes()).toEqual([nameInput]);
    expect(screen.getByDisplayValue(INITIAL_EMAIL)).toHaveAttribute('readOnly');
  });

  it('patches trimmed name with Content-Type and CSRF and keeps email unchanged on 200 (SH-15)', async () => {
    let capturedRequest: Request | null = null;
    server.use(
      http.patch('/api/bff/auth/me', ({ request }) => {
        capturedRequest = request;
        return HttpResponse.json({
          data: {
            name: UPDATED_NAME,
            email: INITIAL_EMAIL,
          },
        });
      }),
    );

    const user = setupUser();
    renderForm();
    const nameInput = screen.getByLabelText(/^Nome$/i);
    await user.clear(nameInput);
    await user.type(nameInput, `  ${UPDATED_NAME}  `);
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Nome$/i)).toHaveValue(UPDATED_NAME);
    });
    expect(screen.getByDisplayValue(INITIAL_EMAIL)).toHaveValue(INITIAL_EMAIL);
    expect(capturedRequest).not.toBeNull();
    expect(capturedRequest!.headers.get('X-CSRF-Token')).toBe('test-csrf-token');
    expect(capturedRequest!.headers.get('Content-Type')).toContain('application/json');
    expect(capturedRequest!.credentials).toBe('include');
    await expect(capturedRequest!.json()).resolves.toEqual({ name: UPDATED_NAME });
    expect(document.body.innerHTML).not.toContain('Bearer');
  });

  it('blocks empty, whitespace-only, and too-long names without calling fetch (SH-15)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const user = setupUser();
    renderForm();
    const nameInput = screen.getByLabelText(/^Nome$/i);

    await user.clear(nameInput);
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));
    await waitFor(() => {
      expect(nameInput).toHaveAccessibleDescription('Informe seu nome.');
    });
    expect(fetchSpy).not.toHaveBeenCalled();

    await user.type(nameInput, '   ');
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));
    await waitFor(() => {
      expect(nameInput).toHaveAccessibleDescription('Informe seu nome.');
    });
    expect(fetchSpy).not.toHaveBeenCalled();

    await user.clear(nameInput);
    await user.type(nameInput, 'a'.repeat(121));
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));
    await waitFor(() => {
      expect(nameInput).toHaveAccessibleDescription('O nome deve ter no máximo 120 caracteres.');
    });
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('maps 422 field errors onto name (SH-22)', async () => {
    server.use(
      http.patch('/api/bff/auth/me', () =>
        HttpResponse.json(
          {
            code: 'VALIDATION_FAILED',
            errors: {
              name: [{ message: 'O nome deve ter no máximo 120 caracteres.' }],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const user = setupUser();
    renderForm();
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Nome$/i)).toHaveAccessibleDescription(
        'O nome deve ter no máximo 120 caracteres.',
      );
    });
  });

  it('shows rate limit message with Retry-After guidance for 429 (SH-23)', async () => {
    server.use(
      http.patch('/api/bff/auth/me', () =>
        HttpResponse.json(
          { code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' },
          { status: 429, headers: { 'Retry-After': '90' } },
        ),
      ),
    );

    const user = setupUser();
    renderForm();
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Aguarde cerca de 2 minutos antes de tentar novamente.',
      );
    });
  });

  it('shows generic rate limit message when Retry-After is absent (SH-23)', async () => {
    server.use(
      http.patch('/api/bff/auth/me', () =>
        HttpResponse.json({ code: 'RATE_LIMIT_EXCEEDED', message: 'Too many.' }, { status: 429 }),
      ),
    );

    const user = setupUser();
    renderForm();
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }));

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toBe(
        'Muitas tentativas. Aguarde antes de tentar novamente.',
      );
    });
  });
});
