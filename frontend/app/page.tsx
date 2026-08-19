import { headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { AuthenticatedShell } from '@/modules/auth/components/authenticated-shell';
import { resolveVerificationSessionGuard } from '@/modules/auth/lib/verification-guard';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

export default async function HomePage() {
  const hdrs = await headers();
  const request = new Request('https://app.localhost/', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);
  const decision = resolveVerificationSessionGuard({
    pathname: '/',
    sessionKind: session?.kind ?? null,
  });

  if (decision.action === 'redirect') {
    redirect(decision.to);
  }

  if (session?.kind === 'session') {
    return (
      <AuthenticatedShell>
        <main className="mx-auto w-full max-w-3xl px-6 py-16">
          <h1 className="font-display text-3xl tracking-tight text-foreground">Início</h1>
          <p className="mt-4 text-lg text-muted">Seus links estarão aqui em breve.</p>
        </main>
      </AuthenticatedShell>
    );
  }

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-3xl flex-col justify-center px-6 py-16">
      <p className="font-display text-5xl tracking-tight text-foreground sm:text-6xl">Fake Link</p>
      <p className="mt-4 max-w-xl text-lg text-muted">Plataforma de encurtamento de URLs.</p>
      <a
        href="#conteudo"
        className="mt-8 inline-flex w-fit items-center rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-foreground"
      >
        Começar
      </a>
      <section id="conteudo" className="sr-only">
        Conteúdo principal
      </section>
    </main>
  );
}
