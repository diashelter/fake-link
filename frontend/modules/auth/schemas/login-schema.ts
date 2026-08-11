import { z } from 'zod';

import { emailSchema } from '@/modules/shared/schemas/email';

export const loginSchema = z.object({
  email: emailSchema.transform((value) => value.toLowerCase()),
  password: z
    .string()
    .min(1, 'Informe a senha.')
    .max(128, 'A senha deve ter no máximo 128 caracteres.'),
});

export type LoginFormValues = z.infer<typeof loginSchema>;
