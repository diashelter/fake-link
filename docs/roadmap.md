# Roadmap do MVP

## Princípios de entrega

- Entregar fatias verticais pequenas, executáveis e observáveis.
- Manter OpenAPI, client TypeScript gerado, testes e documentação na mesma mudança.
- Executar desenvolvimento, testes, análise, build e operação somente por Docker.
- Validar integração com PostgreSQL e as instâncias Redis reais de dados efêmeros e fila; SQLite não faz parte do projeto.
- Usar OpenAPI 3.1 design-first e SemVer para releases e contratos.
- Manter `main` protegida, sem push direto e com todos os checks obrigatórios.
- Usar apenas ambientes local e produção de forma permanente. CI, E2E e benchmark usam composições efêmeras; não haverá staging permanente.
- Não antecipar itens do pós-MVP enquanto os gates da fase corrente não estiverem satisfeitos.

## Fase 0: Foundation

### Entregáveis

- Monorepo com `backend`, `frontend`, `docker`, `docs` e módulos iniciais Auth, Links, Redirects, Analytics, Operations e Shared.
- Escolha, registro e pin das versões exatas stable-supported de PHP, Laravel, Composer, Node.js, Next.js, PostgreSQL, Redis, Nginx e ferramentas no momento do scaffold.
- pnpm fixado por Corepack e lockfile reproduzível para dependências JavaScript.
- Docker Compose com perfis para desenvolvimento, testes, observabilidade e benchmark.
- Containers separados para Nginx, Next.js, Laravel API, PostgreSQL, Redis efêmero, Redis de fila, queue worker, scheduler e Swagger UI.
- HTTPS local pelo Nginx com certificado de desenvolvimento, sem reduzir propriedades de segurança dos cookies.
- Health checks, migrations, seeder determinístico restrito a local/CI e comandos únicos via Docker.
- OpenAPI 3.1 design-first, lint, diff, exemplos executáveis, Swagger UI e geração do client TypeScript.
- Limites do monólito modular e gates Pest para seams, Controllers finos e proibição de acesso cross-module a `Models`.
- Base Next.js server-first, Route Handlers do BFF, TypeScript strict, ESLint, Prettier, Vitest, React Testing Library, MSW e Playwright.
- Base visual com Tailwind CSS, Radix Primitives, tokens próprios e tema claro único.
- Base Laravel API Only com Pest, Pint, Larastan no nível estrito máximo sustentável e PostgreSQL real nos testes.
- OpenTelemetry básico para logs, métricas e traces, já com redaction e IDs de correlação.
- CI de pull request com cobertura, contratos, segurança, builds e smoke da composição.
- Build multiarch, publicação de imagens no GHCR, SBOM e assinatura Cosign preparados para releases SemVer.

### Critérios de saída

- Um novo desenvolvedor inicia o ambiente HTTPS e executa todas as suítes usando apenas Docker.
- Composição sobe com todos os health checks e smoke funcional de frontend, API, PostgreSQL e ambos Redis.
- Um exemplo design-first percorre OpenAPI, implementação, contract test e client TypeScript gerado.
- Gates de arquitetura, análise estrita e cobertura inicial passam sem baseline tolerado.
- Imagens multiarch são reproduzíveis, possuem SBOM e têm assinatura verificável.

## Fase 1: Auth + BFF

### Entregáveis

- Entrada por convite com allowlist exata, resposta genérica contra enumeração e estados `pending_verification`, `active`, `suspended` e `deletion_pending` de `User`.
- Cadastro com senha de 12 a 128 caracteres contendo minúscula, maiúscula, dígito e símbolo, Argon2id e aceite versionado de termos.
- Verificação de e-mail por `POST` explícito, segura contra scanners e prefetch.
- Envio pelo Resend, reenvio de verificação e recuperação de senha com tokens de uso único válidos, respectivamente, por 60 e 30 minutos, retry e rate limiting.
- Login, perfil, logout, revogação de todas as sessões e bloqueio por status do usuário.
- Sessão completa com 7 dias absolutos e 24 horas de inatividade; Restricted Session com 24 horas absolutas e 1 hora de inatividade.
- Perfil limitado à alteração de nome e revogação de todas as sessões após mudança ou reset de senha.
- Token Bearer de acesso direto com expiração absoluta e por inatividade conforme o tipo e write throttle de `last_used_at` de 15 minutos.
- BFF em Next.js Route Handlers com token criptografado no servidor, sessão opaca validada por HMAC e chave externa ao Redis.
- Cookie `__Host-` quando aplicável, `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/` e sem `Domain`.
- Proteção de mutations por allowlist de `Origin` e double-submit CSRF.
- Allowlist de rotas do BFF, `returnUrl` interno seguro e garantia de que o token não chega ao browser.
- Logout seguro quando Redis ou API falham e invalidação da sessão após perda do Redis.
- UI server-first de convite, cadastro, verificação, login, recuperação, termos e sessão.
- React Hook Form com Zod e TanStack Query sem persistência, com defaults documentados e testados.
- OpenAPI, contratos, testes Pest/Vitest/RTL/MSW/Playwright, acessibilidade e telemetria do fluxo.

### Critérios de saída

- Usuário convidado aceita os termos, verifica o e-mail por ação explícita, autentica e encerra uma ou todas as sessões.
- E-mail não permitido, scanner e usuário em estado inválido não ativam conta nem permitem enumeração.
- Nenhum teste de browser, HTML, storage, log ou trace encontra token Bearer.
- CSRF, cookie, `Origin`, HMAC da sessão, safe return URL, perda do Redis e expirações passam nas suítes de segurança.
- Resend funciona no ambiente permitido com DNS de e-mail validável e sem dependência externa nas suítes determinísticas.

## Fase 2: Links + Redirect

### Entregáveis

- Domínio de Links com ownership, listagem, detalhe, pesquisa, título, status e expiração em UTC.
- Slug automático Base36 minúsculo de 8 caracteres, até 5 tentativas de colisão e namespace global case-insensitive.
- Alias personalizado em minúsculas, allowlist, palavras reservadas, constraint concorrente e reserva global permanente.
- Reserva órfã sem proprietário ou destino após exclusão, sempre respondendo `410` e nunca sendo reutilizada.
- Política de destino HTTP/HTTPS para hostname público, rejeição de literais de IP e do próprio domínio curto, aceitação de portas customizadas, remoção de portas padrão redundantes e preservação de query e fragmento.
- Criptografia de aplicação para destino atual, histórico e material de replay, com chave externa à persistência.
- Histórico imutável de destinos e transações que mantêm uma única versão corrente.
- Idempotência por payload normalizado e HMAC, com replay exato criptografado.
- Concorrência otimista com `ETag` para status, expiração e edição, incluindo no-op, comparação e reaplicação explícita na UI.
- Ausência de hard delete de link para o usuário.
- Redirect público `GET` e `HEAD`; raiz envia à landing page, caixa e barra final resolvem, query de entrada é ignorada e percent-encoding ou segmentos extras são rejeitados, com respostas `302`, `404`, `410`, `429` e `503`.
- Cache positivo e negativo criptografado no Redis efêmero, invalidação após commit, fallback para PostgreSQL e tratamento de cache corrompido ou chave ausente.
- Publicação after-response do payload sanitizado de analytics, sem tornar o redirect dependente do Redis de fila ou worker.
- UI responsiva para criar, listar, detalhar, editar, ativar, expirar, copiar e consultar histórico.
- Link bloqueado permanece editável e com histórico e analytics privados, sem deixar o status efetivo `blocked`.
- OpenAPI, contratos, arquitetura, concorrência, E2E, snapshots estáveis em 360 px/desktop, acessibilidade e telemetria do caminho crítico.

### Critérios de saída

- Usuário cria e gerencia links pelo frontend oficial e o destino anterior permanece no histórico criptografado.
- Alias não pode ser sequestrado por caixa, concorrência, exclusão ou reserva órfã.
- Conflito de `ETag` nunca sobrescreve silenciosamente e a UI permite comparar e reaplicar a mudança.
- Redirect correto continua em cache hit com PostgreSQL indisponível e em fallback com Redis efêmero indisponível.
- Falha, parada ou perda aceita da fila de analytics não altera a resposta de redirect válida.
- Cobertura de Links e Redirects atinge 90% de linhas e 85% de branches.

## Fase 3: Analytics

### Entregáveis

- `recordClick` after-response que classifica tráfego e cliente, normaliza referenciador, calcula HMAC diário escopado por link e publica o payload sanitizado.
- Worker idempotente e transacional que consome o Redis de fila, sem IP, user-agent, destino ou referenciador completo.
- Partições diárias de eventos pré-criadas com sete dias de antecedência, retenção automatizada e rebuild controlado.
- Deduplicação diária somente de tráfego humano; demais tipos mantêm unicidade nula.
- Agregados transacionais por hora e dia para totais, únicos humanos, referências e dispositivos; browser e sistema operacional permanecem fora do MVP.
- Retry e replay tardio sem duplicação, além de reconciliação e rebuild idempotentes.
- Queries privadas de resumo, série temporal com buckets densos, referências e dispositivos, com filtros e limites do contrato; referências excedentes são agrupadas em `other`.
- Retenção de 90 dias para eventos detalhados, 366 dias para agregados e 31 dias para resolução horária.
- Dashboard server-first com estados de loading, vazio, erro e atraso, filtros e polling de 60 segundos somente quando visível.
- Gráficos Recharts com alternativa textual acessível e breakdowns completos em tabela ou lista.
- Privacidade verificada em banco, Redis, jobs, logs, métricas e traces, incluindo labels sem alta cardinalidade proibida.
- OTel para idade e profundidade da fila, atraso de analytics, retries, falhas, throughput e latência das consultas.
- OpenAPI, client gerado, contratos, testes de partição/transação/retenção, E2E, acessibilidade e redaction.

### Critérios de saída

- Clique humano aparece no dashboard com atraso `p95 < 60 s` e sem duplicação após retry.
- Bot, preview e unknown são classificados, mas não incrementam únicos humanos.
- Séries são densas e consistentes com eventos e agregados após retry, rebuild e retenção.
- Scans sentinela não encontram nenhum dado bruto proibido ou label sensível.
- Redirect continua independente da persistência e recuperação do pipeline de analytics.

## Fase 4: Operations + Production

### Entregáveis

- Módulo Operations com comandos autorizados para suspender usuário, bloquear link, excluir conta em batches, reconciliar dados e reconstruir agregados.
- Auditoria imutável e sanitizada das operações, com execução retomável e idempotente.
- Ações operacionais sobre `User` não enviam e-mail.
- Stack OpenTelemetry completa com Collector, Grafana, métricas, logs, traces, dashboards e alertas; Better Stack recebe a monitoração externa e notificações operacionais.
- Cloudflare na borda, origem privada, Tailscale para acesso administrativo e Ansible para provisionamento reproduzível.
- Segredos gerenciados com SOPS e procedimento exercitado de rotação e revogação.
- Backups criptografados enviados ao Cloudflare R2, retenção definida e restauração automatizada testada em composição limpa.
- Deploy e rollback por imagens multiarch do GHCR, digest imutável, SBOM e assinatura Cosign verificada.
- Runbooks de deploy, rollback, incidente, fila, bloqueio, revogação de segredos, backup e restauração.
- Termos, privacidade, cookies, retenção, exclusão, processo de abuso interno e demais textos legais revisados por responsável humano.
- Matriz BrowserStack pré-release para versões atual e anterior de Chrome, Edge, Firefox e Safari em desktop e iOS nas combinações aplicáveis.
- WCAG 2.2 AA dos fluxos críticos validada por `axe` e revisão manual.
- Security suite com auditorias Composer/JavaScript, Trivy, Gitleaks, CodeQL semanal e Dependabot.
- Benchmark nominal da referência de 1 milhão de redirects por dia, stress separado, resiliência, smoke público via Cloudflare e load smoke em `main`.
- Alertas e restauração exercitados, capacidade medida e release SemVer automatizada.

### Critérios de saída

- Comandos Operations suspendem, bloqueiam, excluem em batches e reconciliam sem liberar aliases ou vazar dados.
- Ambiente de produção é reproduzível por Ansible, acessível administrativamente apenas pelo caminho definido e usa segredos SOPS.
- Backup do R2 restaura uma composição limpa dentro dos objetivos e passa validações funcionais e de integridade.
- Benchmark na referência de 4 vCPU, 8 GB e 200 GB aprova redirects a 120 RPS por 15 minutos e API privada a 20 RPS por 10 minutos com todos os limites definidos em `docs/testing.md`.
- E2E, contratos, segurança, WCAG, benchmark, privacidade, restore, alertas, revisão legal, domínios, TLS, Resend, Cloudflare e runbooks passam cumulativamente.
- Release assinada é publicada e um rollback para a imagem anterior é demonstrado sem depender de staging permanente.

## Pós-MVP

Backlog explícito, sem compromisso de ordem:

- Cadastro público, denúncia de abuso e reputação de destinos.
- Gestão de tokens de integração.
- QR Codes.
- Domínios personalizados.
- Equipes e workspaces.
- Planos, limites comerciais e billing.
- Geolocalização.
- Links protegidos.
- Limites de cliques.
- Testes A/B e múltiplos destinos.
- Operações de Links em batch para o `User`.
- Exports.
- MFA.
- Status page pública.
- Feature flags.
- Analytics detalhado por browser e sistema operacional.
- Preferências de timezone.

Tokens de integração não fazem parte do MVP. O token direto da sessão de usuário existe para acesso à API, mas não inclui interface de criação, listagem ou revogação de credenciais de integração.

## Bloqueadores de deploy

Os itens abaixo precisam de decisão ou fornecimento externo antes do primeiro deploy de produção:

- Domínio exato da aplicação e domínio curto exato.
- Provedor de VM que cumpra disco criptografado e a referência mínima de 4 vCPU, 8 GB de RAM e 200 GB de disco.
- Versões exatas stable-supported da stack, escolhidas e fixadas no scaffold da Fase 0.
- Textos humanos finais de termos, privacidade, cookies, retenção e exclusão.
- DNS de e-mail necessário para o Resend.

HSTS foi rejeitado permanentemente para este produto, não deve ser implementado e não é item de evolução do roadmap.

## Evolução de backup

- A estratégia inicial usa backup lógico criptografado no Cloudflare R2 e restore exercitado.
- Ao atingir 20 GB de banco ou quando backup mais restore alcançar 2 horas, o que ocorrer primeiro, a estratégia muda para backup físico com recuperação compatível.
- A mudança exige novo teste de restore, atualização dos runbooks e validação dos objetivos de recuperação antes de novo release.
