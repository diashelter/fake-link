import { z } from 'zod';

import { emailSchema } from '@/modules/shared/schemas/email';

export const forgotPasswordSchema = z.object({
  email: emailSchema.transform((value) => value.toLowerCase()),
});

export type ForgotPasswordFormValues = z.infer<typeof forgotPasswordSchema>;
