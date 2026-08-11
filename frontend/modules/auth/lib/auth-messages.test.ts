import { describe, expect, it } from 'vitest';

import { formatRetryAfter, messageForAuthError } from './auth-messages';

describe('messageForAuthError', () => {
  it('maps INVALID_CREDENTIALS to pt-BR anti-enum message', () => {
    expect(messageForAuthError('INVALID_CREDENTIALS', 401)).toBe('E-mail ou senha incorretos.');
  });

  it('maps ACCOUNT_SUSPENDED to pt-BR message', () => {
    expect(messageForAuthError('ACCOUNT_SUSPENDED', 403)).toBe('Esta conta está suspensa.');
  });

  it('maps ACCOUNT_PENDING_DELETION to pt-BR message', () => {
    expect(messageForAuthError('ACCOUNT_PENDING_DELETION', 403)).toBe(
      'Esta conta está em processo de exclusão.',
    );
  });

  it('maps RATE_LIMIT_EXCEEDED to pt-BR message', () => {
    expect(messageForAuthError('RATE_LIMIT_EXCEEDED', 429)).toBe(
      'Muitas tentativas. Aguarde antes de tentar novamente.',
    );
  });

  it('maps VALIDATION_FAILED to pt-BR message', () => {
    expect(messageForAuthError('VALIDATION_FAILED', 422)).toBe('Verifique os campos informados.');
  });

  it('maps 504 to gateway pt-BR message', () => {
    expect(messageForAuthError(undefined, 504)).toBe(
      'Não foi possível conectar ao serviço. Tente novamente.',
    );
  });

  it('maps 500 and 503 to generic pt-BR message', () => {
    expect(messageForAuthError(undefined, 500)).toBe('Algo deu errado. Tente novamente.');
    expect(messageForAuthError(undefined, 503)).toBe('Algo deu errado. Tente novamente.');
  });
});

describe('formatRetryAfter', () => {
  it('returns null for invalid values', () => {
    expect(formatRetryAfter(null)).toBeNull();
    expect(formatRetryAfter(-1)).toBeNull();
    expect(formatRetryAfter(Number.NaN)).toBeNull();
  });

  it('formats seconds in pt-BR', () => {
    expect(formatRetryAfter(1)).toBe('Aguarde 1 segundo antes de tentar novamente.');
    expect(formatRetryAfter(45)).toBe('Aguarde 45 segundos antes de tentar novamente.');
  });

  it('formats minutes in pt-BR', () => {
    expect(formatRetryAfter(90)).toBe('Aguarde cerca de 2 minutos antes de tentar novamente.');
  });
});
