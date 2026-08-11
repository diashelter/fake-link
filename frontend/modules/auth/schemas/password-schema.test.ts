import { describe, expect, it } from 'vitest';

import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH, passwordSchema } from './password-schema';

const VALID = 'Abcdefghij1!';

describe('passwordSchema (RGR-08)', () => {
  it('exports OpenAPI-aligned length bounds', () => {
    expect(PASSWORD_MIN_LENGTH).toBe(12);
    expect(PASSWORD_MAX_LENGTH).toBe(128);
  });

  it('accepts a password meeting length and ASCII composition', () => {
    expect(passwordSchema.parse(VALID)).toBe(VALID);
  });

  it('accepts password at minimum length 12', () => {
    const password = 'Abcdefghij1!';
    expect(password.length).toBe(12);
    expect(passwordSchema.parse(password)).toBe(password);
  });

  it('accepts password at maximum length 128', () => {
    const password = `${'Aa1!'.repeat(31)}Aa1!`;
    expect(password.length).toBe(128);
    expect(passwordSchema.parse(password)).toBe(password);
  });

  it('rejects password shorter than 12 with pt-BR message', () => {
    const result = passwordSchema.safeParse('Abcdefgh1!');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('A senha deve ter pelo menos 12 caracteres.');
    }
  });

  it('rejects password longer than 128 with pt-BR message', () => {
    const password = `${'Aa1!'.repeat(32)}A`;
    expect(password.length).toBeGreaterThan(128);
    const result = passwordSchema.safeParse(password);
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('A senha deve ter no máximo 128 caracteres.');
    }
  });

  it('rejects password without ASCII lowercase with pt-BR message', () => {
    const result = passwordSchema.safeParse('ABCDEFGHIJ1!');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some(
          (issue) => issue.message === 'A senha deve conter uma letra minúscula.',
        ),
      ).toBe(true);
    }
  });

  it('rejects password without ASCII uppercase with pt-BR message', () => {
    const result = passwordSchema.safeParse('abcdefghij1!');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some(
          (issue) => issue.message === 'A senha deve conter uma letra maiúscula.',
        ),
      ).toBe(true);
    }
  });

  it('rejects password without ASCII digit with pt-BR message', () => {
    const result = passwordSchema.safeParse('Abcdefghij!!');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some((issue) => issue.message === 'A senha deve conter um dígito.'),
      ).toBe(true);
    }
  });

  it('rejects password without ASCII symbol with pt-BR message', () => {
    const result = passwordSchema.safeParse('Abcdefghij12');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(
        result.error.issues.some((issue) => issue.message === 'A senha deve conter um símbolo.'),
      ).toBe(true);
    }
  });

  it('accepts passwords using edge ASCII symbols ! / : @ [ ` { ~', () => {
    for (const symbol of ['!', '/', ':', '@', '[', '`', '{', '~']) {
      const password = `Abcdefghij1${symbol}`;
      expect(passwordSchema.parse(password)).toBe(password);
    }
  });

  it('rejects password that only has Unicode letters without ASCII composition classes', () => {
    // Length ok but no ASCII lower/upper/digit/symbol categories required by OpenAPI Password
    const result = passwordSchema.safeParse('ÁÉÍÓÚáéíóú12');
    expect(result.success).toBe(false);
  });
});
