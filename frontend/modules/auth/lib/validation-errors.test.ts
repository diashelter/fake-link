import { describe, expect, it, vi } from 'vitest';

import {
  applyServerFieldErrors,
  messageForFieldError,
  messageForTokenFieldError,
} from './validation-errors';

describe('messageForFieldError (PW-11, PW-17)', () => {
  it('maps PASSWORD_REUSED to pt-BR password message', () => {
    expect(messageForFieldError('PASSWORD_REUSED')).toBe(
      'A nova senha deve ser diferente da senha atual.',
    );
  });

  it('returns null for unknown codes', () => {
    expect(messageForFieldError('UNKNOWN_CODE')).toBeNull();
    expect(messageForFieldError(undefined)).toBeNull();
  });
});

describe('messageForTokenFieldError (PW-11)', () => {
  it('returns uniform pt-BR message for token field', () => {
    expect(messageForTokenFieldError('token')).toBe(
      'Link de redefinição inválido ou expirado.',
    );
  });
});

describe('applyServerFieldErrors (PW-11, PW-17, PW-18)', () => {
  it('applies PASSWORD_REUSED object item onto password as pt-BR', () => {
    const setError = vi.fn();
    const applied = applyServerFieldErrors(
      {
        password: [{ code: 'PASSWORD_REUSED', message: 'The new password must be different.' }],
      },
      ['password', 'token'] as const,
      setError,
    );

    expect(applied).toBe(true);
    expect(setError).toHaveBeenCalledWith('password', {
      type: 'server',
      message: 'A nova senha deve ser diferente da senha atual.',
    });
  });

  it('applies legacy string item as the field message', () => {
    const setError = vi.fn();
    const applied = applyServerFieldErrors(
      { email: 'Informe um e-mail válido.' },
      ['email'] as const,
      setError,
    );

    expect(applied).toBe(true);
    expect(setError).toHaveBeenCalledWith('email', {
      type: 'server',
      message: 'Informe um e-mail válido.',
    });
  });

  it('applies uniform pt-BR for any token error without echoing English API message', () => {
    const setError = vi.fn();
    const applied = applyServerFieldErrors(
      {
        token: [{ code: 'INVALID_TOKEN', message: 'The reset token is invalid or expired.' }],
      },
      ['password', 'token'] as const,
      setError,
    );

    expect(applied).toBe(true);
    expect(setError).toHaveBeenCalledWith('token', {
      type: 'server',
      message: 'Link de redefinição inválido ou expirado.',
    });
    expect(setError.mock.calls[0]?.[1]?.message).not.toContain('The reset token');
  });

  it('applies uniform token message for a legacy string item', () => {
    const setError = vi.fn();
    applyServerFieldErrors(
      { token: ['The reset token is invalid.'] },
      ['token'] as const,
      setError,
    );

    expect(setError).toHaveBeenCalledWith('token', {
      type: 'server',
      message: 'Link de redefinição inválido ou expirado.',
    });
  });

  it('ignores fields that are not in allowedFields', () => {
    const setError = vi.fn();
    const applied = applyServerFieldErrors(
      { secret: 'should-not-leak', password: ['ok'] },
      ['password'] as const,
      setError,
    );

    expect(applied).toBe(true);
    expect(setError).toHaveBeenCalledTimes(1);
    expect(setError).toHaveBeenCalledWith('password', { type: 'server', message: 'ok' });
  });

  it('returns false when no allowed field errors apply', () => {
    const setError = vi.fn();
    const applied = applyServerFieldErrors(
      { other: 'ignored' },
      ['password'] as const,
      setError,
    );

    expect(applied).toBe(false);
    expect(setError).not.toHaveBeenCalled();
  });
});
