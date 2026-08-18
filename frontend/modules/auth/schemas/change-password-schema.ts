import { z } from 'zod';

import { passwordSchema } from './password-schema';

export const changePasswordSchema = z
  .object({
    current_password: z
      .string()
      .min(1, 'Informe sua senha atual.')
      .max(128, 'A senha deve ter no máximo 128 caracteres.'),
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'As senhas não coincidem.',
    path: ['password_confirmation'],
  });

export type ChangePasswordFormValues = z.infer<typeof changePasswordSchema>;
