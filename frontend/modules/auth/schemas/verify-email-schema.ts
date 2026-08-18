import { z } from 'zod';

export const verifyEmailSchema = z.object({
  token: z
    .string({ error: 'Informe o código de verificação.' })
    .min(1, 'Informe o código de verificação.')
    .refine((value) => /\S/.test(value), 'Informe o código de verificação.'),
});

export type VerifyEmailFormValues = z.infer<typeof verifyEmailSchema>;
