import { cookies } from 'next/headers';

import { ensurePreAuthCsrfCookies } from '@/modules/auth/bff/csrf';
import { ResetPasswordForm } from '@/modules/auth/components/reset-password-form';

type ResetPasswordPageProps = {
  searchParams: Promise<{ token?: string }>;
};

function decodeResetToken(token: string | undefined): string | undefined {
  if (!token) {
    return undefined;
  }

  try {
    return decodeURIComponent(token);
  } catch {
    return token;
  }
}

export default async function ResetPasswordPage({ searchParams }: ResetPasswordPageProps) {
  const params = await searchParams;
  ensurePreAuthCsrfCookies(await cookies());

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Redefinir senha</h1>
      <p className="mt-2 text-sm text-muted">Defina uma nova senha para sua conta.</p>
      <div className="mt-8">
        <ResetPasswordForm initialToken={decodeResetToken(params.token)} />
      </div>
    </main>
  );
}
