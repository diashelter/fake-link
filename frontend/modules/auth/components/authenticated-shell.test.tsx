import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

vi.mock('@/modules/auth/lib/client-cookie', () => ({
  readClientCookie: vi.fn(() => 'test-csrf-token'),
}));

import { AuthenticatedShell } from './authenticated-shell';

afterEach(() => {
  cleanup();
});

describe('AuthenticatedShell (SH-16, SH-19)', () => {
  it('renders Início, Conta, Sair, and the children slot', () => {
    render(
      <AuthenticatedShell>
        <p>Conteúdo autenticado</p>
      </AuthenticatedShell>,
    );

    expect(screen.getByRole('link', { name: 'Início' })).toHaveAttribute('href', '/');
    expect(screen.getByRole('link', { name: 'Conta' })).toHaveAttribute('href', '/settings');
    expect(screen.getByRole('button', { name: 'Sair' })).toBeInTheDocument();
    expect(screen.getByText('Conteúdo autenticado')).toBeInTheDocument();
  });
});
