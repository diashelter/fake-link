import { describe, expect, it } from 'vitest';

import { forgotPasswordSchema } from './forgot-password-schema';

describe('forgotPasswordSchema (PW-18, PW-24)', () => {
  it('trims and lowercases email on a valid payload', () => {
    expect(forgotPasswordSchema.parse({ email: '  User@Example.COM  ' })).toEqual({
      email: 'user@example.com',
    });
  });

  it('rejects missing email with pt-BR message', () => {
    const result = forgotPasswordSchema.safeParse({ email: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail.');
    }
  });

  it('rejects invalid email format with pt-BR message', () => {
    const result = forgotPasswordSchema.safeParse({ email: 'not-an-email' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail válido.');
    }
  });

  it('rejects email longer than 254 characters', () => {
    const local = 'a'.repeat(243);
    const tooLong = `${local}@example.com`;
    const result = forgotPasswordSchema.safeParse({ email: tooLong });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('O e-mail deve ter no máximo 254 caracteres.');
    }
  });

  it('accepts email at max length 254 after trim', () => {
    const local = 'a'.repeat(242);
    const email = `${local}@example.com`;
    expect(email.length).toBe(254);
    expect(forgotPasswordSchema.parse({ email }).email).toBe(email);
  });
});
