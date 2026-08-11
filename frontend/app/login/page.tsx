import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { ensurePreAuthCsrfCookies } from '@/modules/auth/bff/csrf';
import { sanitizeReturnUrl } from '@/modules/auth/bff/return-url';
import { LoginForm } from '@/modules/auth/components/login-form';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

type LoginPageProps = {
  searchParams: Promise<{ returnUrl?: string }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  const hdrs = await headers();
  const request = new Request('https://app.localhost/login', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);

  if (session?.kind === 'session') {
    redirect(sanitizeReturnUrl(params.returnUrl, '/'));
  }

  if (session?.kind === 'verification') {
    redirect('/verify-email');
  }

  ensurePreAuthCsrfCookies(await cookies());

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Entrar</h1>
      <p className="mt-2 text-sm text-muted">Acesse sua conta Fake Link.</p>
      <div className="mt-8">
        <LoginForm returnUrl={params.returnUrl} />
      </div>
    </main>
  );
}
