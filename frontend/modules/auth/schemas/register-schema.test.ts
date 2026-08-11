import { describe, expect, it } from 'vitest';

import { registerSchema } from './register-schema';

const VALID = {
  name: 'Helter Dias',
  email: 'user@example.com',
  password: 'Abcdefghij1!',
  password_confirmation: 'Abcdefghij1!',
  accept_terms: true as const,
};

describe('registerSchema (RGR-06, RGR-07, RGR-10)', () => {
  it('accepts a valid RegisterRequest-shaped payload', () => {
    expect(registerSchema.parse(VALID)).toEqual(VALID);
  });

  it('trims name and lowercases email before output', () => {
    expect(
      registerSchema.parse({
        ...VALID,
        name: '  Helter Dias  ',
        email: '  User@Example.COM  ',
      }),
    ).toEqual({
      ...VALID,
      name: 'Helter Dias',
      email: 'user@example.com',
    });
  });

  it('rejects empty name after trim with pt-BR message', () => {
    const result = registerSchema.safeParse({ ...VALID, name: '   ' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe seu nome.');
    }
  });

  it('rejects name longer than 120 characters with pt-BR message', () => {
    const result = registerSchema.safeParse({ ...VALID, name: 'a'.repeat(121) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('O nome deve ter no máximo 120 caracteres.');
    }
  });

  it('rejects invalid email with pt-BR message', () => {
    const result = registerSchema.safeParse({ ...VALID, email: 'not-an-email' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail válido.');
    }
  });

  it('rejects accept_terms false with Terms pt-BR message (RGR-06)', () => {
    const result = registerSchema.safeParse({ ...VALID, accept_terms: false });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some(
          (issue) => issue.message === 'Você precisa aceitar os Termos de uso.',
        ),
      ).toBe(true);
    }
  });

  it('rejects missing accept_terms with Terms pt-BR message', () => {
    const { accept_terms: _omit, ...withoutTerms } = VALID;
    const result = registerSchema.safeParse(withoutTerms);
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some(
          (issue) => issue.message === 'Você precisa aceitar os Termos de uso.',
        ),
      ).toBe(true);
    }
  });

  it('rejects password_confirmation mismatch with field error (RGR-10)', () => {
    const result = registerSchema.safeParse({
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

  it('rejects weak password via shared passwordSchema (RGR-08 compose)', () => {
    const result = registerSchema.safeParse({
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

  it('accepts name at max length 120', () => {
    const name = 'a'.repeat(120);
    expect(registerSchema.parse({ ...VALID, name }).name).toBe(name);
  });
});
