import { z } from 'zod';

/** OpenAPI Password: 12–128 chars with ASCII lower, upper, digit, and symbol. */
export const PASSWORD_MIN_LENGTH = 12;
export const PASSWORD_MAX_LENGTH = 128;

const ASCII_SYMBOL = /[!-/:-@[-`{-~]/;

export const passwordSchema = z
  .string()
  .min(PASSWORD_MIN_LENGTH, 'A senha deve ter pelo menos 12 caracteres.')
  .max(PASSWORD_MAX_LENGTH, 'A senha deve ter no máximo 128 caracteres.')
  .regex(/[a-z]/, 'A senha deve conter uma letra minúscula.')
  .regex(/[A-Z]/, 'A senha deve conter uma letra maiúscula.')
  .regex(/[0-9]/, 'A senha deve conter um dígito.')
  .regex(ASCII_SYMBOL, 'A senha deve conter um símbolo.');
