import { describe, expect, it } from 'vitest';

import { updateProfileSchema } from './update-profile-schema';

describe('updateProfileSchema (SH-27)', () => {
  it('accepts a name within 1–120 characters', () => {
    expect(updateProfileSchema.parse({ name: 'Helter Dias' })).toEqual({ name: 'Helter Dias' });
  });

  it('trims external whitespace from name', () => {
    expect(updateProfileSchema.parse({ name: '  Helter Dias  ' })).toEqual({
      name: 'Helter Dias',
    });
  });

  it('rejects empty name with pt-BR message', () => {
    const result = updateProfileSchema.safeParse({ name: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe seu nome.');
    }
  });

  it('rejects whitespace-only name after trim', () => {
    const result = updateProfileSchema.safeParse({ name: '   ' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('Informe seu nome.');
    }
  });

  it('accepts name at max length 120', () => {
    const name = 'a'.repeat(120);
    expect(updateProfileSchema.parse({ name }).name).toBe(name);
  });

  it('rejects name longer than 120 characters with pt-BR message', () => {
    const result = updateProfileSchema.safeParse({ name: 'a'.repeat(121) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe('O nome deve ter no máximo 120 caracteres.');
    }
  });
});
