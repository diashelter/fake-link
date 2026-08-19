import { cookies } from 'next/headers';

import { ensurePreAuthCsrfCookies } from '@/modules/auth/bff/csrf';
import { ForgotPasswordForm } from '@/modules/auth/components/forgot-password-form';

export default async function ForgotPasswordPage() {
  ensurePreAuthCsrfCookies(await cookies());

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Recuperar senha</h1>
      <p className="mt-2 text-sm text-muted">
        Informe seu e-mail para receber instruções de redefinição.
      </p>
      <div className="mt-8">
        <ForgotPasswordForm />
      </div>
    </main>
  );
}
