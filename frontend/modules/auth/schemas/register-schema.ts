import { z } from 'zod';

import { emailSchema } from '@/modules/shared/schemas/email';

import { passwordSchema } from './password-schema';

export const registerSchema = z
  .object({
    name: z
      .string()
      .trim()
      .min(1, 'Informe seu nome.')
      .max(120, 'O nome deve ter no máximo 120 caracteres.'),
    email: emailSchema.transform((value) => value.toLowerCase()),
    password: passwordSchema,
    password_confirmation: z.string(),
    accept_terms: z.literal(true, {
      message: 'Você precisa aceitar os Termos de uso.',
    }),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'As senhas não coincidem.',
    path: ['password_confirmation'],
  });

export type RegisterFormValues = z.infer<typeof registerSchema>;
