import { describe, expect, it } from 'vitest';
import { z } from 'zod';
import { emailSchema } from './email';

describe('emailSchema', () => {
  it('accepts a valid email after trim', () => {
    expect(emailSchema.parse('  user@example.com  ')).toBe('user@example.com');
  });

  it('rejects an invalid email', () => {
    const result = emailSchema.safeParse('not-an-email');
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe um e-mail válido.');
    }
  });

  it('rejects emails longer than 254 characters', () => {
    const local = 'a'.repeat(243);
    const tooLong = `${local}@example.com`;
    expect(tooLong.length).toBeGreaterThan(254);
    const result = emailSchema.safeParse(tooLong);
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('O e-mail deve ter no máximo 254 caracteres.');
    }
  });

  it('proves zod is available', () => {
    expect(z.string().parse('ok')).toBe('ok');
  });
});
