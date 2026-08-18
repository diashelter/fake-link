import { headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { VerifyEmailForm } from '@/modules/auth/components/verify-email-form';
import { resolveVerificationSessionGuard } from '@/modules/auth/lib/verification-guard';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

type VerifyEmailPageProps = {
  searchParams: Promise<{ token?: string }>;
};

export default async function VerifyEmailPage({ searchParams }: VerifyEmailPageProps) {
  const params = await searchParams;
  const hdrs = await headers();
  const request = new Request('https://app.localhost/verify-email', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);
  const decision = resolveVerificationSessionGuard({
    pathname: '/verify-email',
    sessionKind: session?.kind ?? null,
  });

  if (decision.action === 'redirect') {
    redirect(decision.to);
  }

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Confirme seu e-mail</h1>
      <p className="mt-2 text-sm text-muted">
        Enviamos um link para o seu e-mail. Cole o código abaixo ou use o link recebido.
      </p>
      <div className="mt-8">
        <VerifyEmailForm initialToken={params.token} />
      </div>
    </main>
  );
}
