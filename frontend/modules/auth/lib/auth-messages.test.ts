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

  it('maps REGISTRATION_NOT_ALLOWED to uniform pt-BR anti-enum message (RGR-04, RGR-05)', () => {
    const expected =
      'Não foi possível concluir o cadastro. Verifique seus dados ou entre em contato com o suporte.';
    expect(messageForAuthError('REGISTRATION_NOT_ALLOWED', 403)).toBe(expected);
  });

  it('returns the same REGISTRATION_NOT_ALLOWED string for invite and duplicate scenarios', () => {
    const inviteMessage = messageForAuthError('REGISTRATION_NOT_ALLOWED', 403);
    const duplicateMessage = messageForAuthError('REGISTRATION_NOT_ALLOWED', 403);
    expect(inviteMessage).toBe(duplicateMessage);
    expect(inviteMessage).toBe(
      'Não foi possível concluir o cadastro. Verifique seus dados ou entre em contato com o suporte.',
    );
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

  it('maps INVALID_VERIFICATION_TOKEN to uniform pt-BR message (EV-08)', () => {
    expect(messageForAuthError('INVALID_VERIFICATION_TOKEN', 403)).toBe(
      'Link de verificação inválido ou expirado.',
    );
  });

  it('maps EMAIL_ALREADY_VERIFIED to pt-BR login guidance (EV-09)', () => {
    expect(messageForAuthError('EMAIL_ALREADY_VERIFIED', 403)).toBe(
      'Este e-mail já foi confirmado. Faça login para continuar.',
    );
  });

  it('maps UNAUTHENTICATED to expired-session pt-BR message (EV-10)', () => {
    expect(messageForAuthError('UNAUTHENTICATED', 401)).toBe(
      'Sua sessão expirou. Faça login novamente.',
    );
  });

  it('maps missing code with 401 status to the same expired-session message (EV-10)', () => {
    expect(messageForAuthError(undefined, 401)).toBe('Sua sessão expirou. Faça login novamente.');
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
