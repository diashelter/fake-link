import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { ensurePreAuthCsrfCookies } from '@/modules/auth/bff/csrf';
import { RegisterForm } from '@/modules/auth/components/register-form';
import { getAuthTermsCurrentVersion } from '@/modules/auth/lib/auth-terms';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

export default async function RegisterPage() {
  const hdrs = await headers();
  const request = new Request('https://app.localhost/register', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);

  if (session?.kind === 'session') {
    redirect('/');
  }

  if (session?.kind === 'verification') {
    redirect('/verify-email');
  }

  ensurePreAuthCsrfCookies(await cookies());

  const termsVersion = getAuthTermsCurrentVersion();

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Criar conta</h1>
      <p className="mt-2 text-sm text-muted">Cadastre-se no Fake Link.</p>
      <div className="mt-8">
        <RegisterForm termsVersion={termsVersion} />
      </div>
    </main>
  );
}
