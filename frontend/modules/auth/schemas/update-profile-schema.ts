import { z } from 'zod';

export const updateProfileSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, 'Informe seu nome.')
    .max(120, 'O nome deve ter no máximo 120 caracteres.'),
});

export type UpdateProfileFormValues = z.infer<typeof updateProfileSchema>;
