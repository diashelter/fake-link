import { describe, expect, it } from 'vitest';

import { changePasswordSchema } from './change-password-schema';

const VALID = {
  current_password: 'old-secret',
  password: 'Abcdefghij1!',
  password_confirmation: 'Abcdefghij1!',
};

describe('changePasswordSchema (PW-18, PW-24)', () => {
  it('accepts a valid ChangePasswordRequest-shaped payload', () => {
    expect(changePasswordSchema.parse(VALID)).toEqual(VALID);
  });

  it('rejects missing current_password with pt-BR message', () => {
    const result = changePasswordSchema.safeParse({ ...VALID, current_password: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe sua senha atual.');
    }
  });

  it('accepts current_password at max length 128', () => {
    const current_password = 'a'.repeat(128);
    expect(changePasswordSchema.parse({ ...VALID, current_password }).current_password).toBe(
      current_password,
    );
  });

  it('rejects current_password longer than 128 characters', () => {
    const result = changePasswordSchema.safeParse({
      ...VALID,
      current_password: 'a'.repeat(129),
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('A senha deve ter no máximo 128 caracteres.');
    }
  });

  it('does not require composition rules on current_password', () => {
    const current_password = 'a'.repeat(12);
    expect(changePasswordSchema.parse({ ...VALID, current_password }).current_password).toBe(
      current_password,
    );
  });

  it('rejects weak new password via shared passwordSchema', () => {
    const result = changePasswordSchema.safeParse({
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

  it('rejects password_confirmation mismatch with field error', () => {
    const result = changePasswordSchema.safeParse({
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
});
