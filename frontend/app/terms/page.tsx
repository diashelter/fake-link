import { getAuthTermsCurrentVersion } from '@/modules/auth/lib/auth-terms';

export default function TermsPage() {
  const termsVersion = getAuthTermsCurrentVersion();

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-2xl flex-col px-6 py-16">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Termos de uso</h1>
      <p className="mt-2 text-sm text-muted">Versão {termsVersion}</p>
      <div className="mt-8 space-y-4 text-sm leading-relaxed text-foreground">
        <p>
          Esta é uma versão provisória dos Termos de uso do Fake Link. O conteúdo jurídico final
          será publicado nesta página em uma atualização futura.
        </p>
        <p>
          Ao criar uma conta, você confirma que leu e aceitou a versão vigente dos Termos exibida
          no momento do cadastro.
        </p>
      </div>
    </main>
  );
}
