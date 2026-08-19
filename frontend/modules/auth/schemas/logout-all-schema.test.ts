import { describe, expect, it } from 'vitest';

import { logoutAllSchema } from './logout-all-schema';

describe('logoutAllSchema (SH-28)', () => {
  it('accepts current_password within max length 128', () => {
    expect(logoutAllSchema.parse({ current_password: 'old-secret' })).toEqual({
      current_password: 'old-secret',
    });
  });

  it('rejects empty current_password with pt-BR message', () => {
    const result = logoutAllSchema.safeParse({ current_password: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe sua senha atual.');
    }
  });

  it('accepts current_password at max length 128', () => {
    const current_password = 'a'.repeat(128);
    expect(logoutAllSchema.parse({ current_password }).current_password).toBe(current_password);
  });

  it('rejects current_password longer than 128 characters', () => {
    const result = logoutAllSchema.safeParse({ current_password: 'a'.repeat(129) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('A senha deve ter no máximo 128 caracteres.');
    }
  });

  it('does not require composition rules on current_password', () => {
    const current_password = 'a'.repeat(12);
    expect(logoutAllSchema.parse({ current_password }).current_password).toBe(current_password);
  });
});
