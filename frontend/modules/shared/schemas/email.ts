import { z } from 'zod';

/** OpenAPI-aligned email bound — max 254 after trim. */
export const emailSchema = z
  .string()
  .trim()
  .min(1, 'Informe um e-mail.')
  .email('Informe um e-mail válido.')
  .max(254, 'O e-mail deve ter no máximo 254 caracteres.');
