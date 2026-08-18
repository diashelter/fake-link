import { z } from 'zod';

import { emailSchema } from '@/modules/shared/schemas/email';

import { passwordSchema } from './password-schema';

export const resetPasswordSchema = z
  .object({
    email: emailSchema.transform((v) => v.toLowerCase()),
    token: z
      .string()
      .min(1, 'Informe o código de recuperação.')
      .refine((token) => token.trim().length > 0, 'Informe o código de recuperação.'),
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'As senhas não coincidem.',
    path: ['password_confirmation'],
  });

export type ResetPasswordFormValues = z.infer<typeof resetPasswordSchema>;
