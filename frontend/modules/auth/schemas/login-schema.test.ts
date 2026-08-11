import { describe, expect, it } from 'vitest';

import { loginSchema } from './login-schema';

describe('loginSchema (LOG-07)', () => {
  it('trims, lowercases email and accepts valid password', () => {
    expect(loginSchema.parse({ email: '  User@Example.COM  ', password: 'secret' })).toEqual({
      email: 'user@example.com',
      password: 'secret',
    });
  });

  it('rejects missing email with pt-BR message', () => {
    const result = loginSchema.safeParse({ email: '', password: 'secret' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail.');
    }
  });

  it('rejects invalid email with pt-BR message', () => {
    const result = loginSchema.safeParse({ email: 'not-an-email', password: 'secret' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail válido.');
    }
  });

  it('rejects email longer than 254 characters', () => {
    const local = 'a'.repeat(243);
    const tooLong = `${local}@example.com`;
    const result = loginSchema.safeParse({ email: tooLong, password: 'secret' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('O e-mail deve ter no máximo 254 caracteres.');
    }
  });

  it('rejects missing password with pt-BR message', () => {
    const result = loginSchema.safeParse({ email: 'user@example.com', password: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe a senha.');
    }
  });

  it('rejects password longer than 128 characters without composition rules', () => {
    const result = loginSchema.safeParse({
      email: 'user@example.com',
      password: 'a'.repeat(129),
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('A senha deve ter no máximo 128 caracteres.');
    }
  });

  it('accepts password at max length 128', () => {
    const password = 'a'.repeat(128);
    expect(loginSchema.parse({ email: 'user@example.com', password }).password).toBe(password);
  });
});
