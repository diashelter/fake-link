export default function HomePage() {
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
