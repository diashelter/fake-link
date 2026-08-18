import { describe, expect, it } from 'vitest';

import { resetPasswordSchema } from './reset-password-schema';

const VALID = {
  email: 'user@example.com',
  token: 'reset-token-opaque',
  password: 'Abcdefghij1!',
  password_confirmation: 'Abcdefghij1!',
};

describe('resetPasswordSchema (PW-18, PW-24)', () => {
  it('accepts a valid ResetPasswordRequest-shaped payload', () => {
    expect(resetPasswordSchema.parse(VALID)).toEqual(VALID);
  });

  it('trims and lowercases email without mutating token', () => {
    const token = '  opaque-token  ';
    expect(
      resetPasswordSchema.parse({
        ...VALID,
        email: '  User@Example.COM  ',
        token,
      }),
    ).toEqual({
      ...VALID,
      email: 'user@example.com',
      token,
    });
  });

  it('rejects empty token with pt-BR message', () => {
    const result = resetPasswordSchema.safeParse({ ...VALID, token: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some((issue) => issue.message === 'Informe o código de recuperação.'),
      ).toBe(true);
    }
  });

  it('rejects whitespace-only token without trimming the value', () => {
    const result = resetPasswordSchema.safeParse({ ...VALID, token: '   ' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some((issue) => issue.message === 'Informe o código de recuperação.'),
      ).toBe(true);
    }
  });

  it('keeps token with surrounding whitespace unchanged on success', () => {
    const token = ' abc ';
    expect(resetPasswordSchema.parse({ ...VALID, token }).token).toBe(token);
  });

  it('rejects password_confirmation mismatch with field error', () => {
    const result = resetPasswordSchema.safeParse({
      ...VALID,
      password_confirmation: 'Different1!xx',
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      const issue = result.error.issues.find(
        (item) => item.path.join('.') === 'password_confirmation',
      );
      expect(issue?.message).toBe('As senhas não coincidem.');
    }
  });

  it('rejects weak password via shared passwordSchema', () => {
    const result = resetPasswordSchema.safeParse({
      ...VALID,
      password: 'short',
      password_confirmation: 'short',
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some(
          (issue) => issue.message === 'A senha deve ter pelo menos 12 caracteres.',
        ),
      ).toBe(true);
    }
  });

  it('rejects invalid email with pt-BR message', () => {
    const result = resetPasswordSchema.safeParse({ ...VALID, email: 'not-an-email' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail válido.');
    }
  });
});
