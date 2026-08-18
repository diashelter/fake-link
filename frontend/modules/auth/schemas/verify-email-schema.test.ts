import { describe, expect, it } from 'vitest';

import { verifyEmailSchema } from './verify-email-schema';

describe('verifyEmailSchema (EV-18, EV-22)', () => {
  it('accepts a token with minLength 1', () => {
    expect(verifyEmailSchema.parse({ token: 'a' })).toEqual({ token: 'a' });
  });

  it('preserves surrounding whitespace on a non-empty token (no trim)', () => {
    expect(verifyEmailSchema.parse({ token: '  opaque-token  ' })).toEqual({
      token: '  opaque-token  ',
    });
  });

  it('rejects an empty token with pt-BR message', () => {
    const result = verifyEmailSchema.safeParse({ token: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe o código de verificação.');
    }
  });

  it('rejects a missing token with pt-BR message', () => {
    const result = verifyEmailSchema.safeParse({});
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe o código de verificação.');
    }
  });

  it('rejects a whitespace-only token without trimming it into a valid value', () => {
    const result = verifyEmailSchema.safeParse({ token: '   ' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe o código de verificação.');
    }
  });
});
