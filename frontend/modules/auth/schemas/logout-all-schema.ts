import { z } from 'zod';

export const logoutAllSchema = z.object({
  current_password: z
    .string()
    .min(1, 'Informe sua senha atual.')
    .max(128, 'A senha deve ter no máximo 128 caracteres.'),
});

export type LogoutAllFormValues = z.infer<typeof logoutAllSchema>;
