# Estratégia de testes e qualidade

## 1. Objetivos

- Proteger regras de domínio, contratos HTTP, privacidade e limites entre módulos.
- Detectar regressões em autenticação, BFF, Links, Redirects, Analytics e Operations.
- Demonstrar que o redirect não depende da persistência síncrona de analytics.
- Manter aplicação, OpenAPI e client TypeScript gerado no mesmo contrato.
- Validar segurança, acessibilidade, compatibilidade, capacidade, recuperação e operação antes de cada release.

## 2. Princípios e ambiente

- Todo teste, análise, build e benchmark é executado em containers. Docker Compose é a interface padrão e não há caminho local alternativo suportado.
- Testes backend de integração usam PostgreSQL e as duas instâncias Redis reais, separadas para dados efêmeros e fila. SQLite in-memory é permitido somente em execuções rápidas via `make test-backend` (unit e feature sem I/O real); não substitui integração com PostgreSQL.
- Perfis do Docker Compose separam desenvolvimento, testes, observabilidade e benchmark sem criar um ambiente de staging permanente.
- CI, smoke tests e benchmarks criam composições efêmeras e descartáveis. Os únicos ambientes duradouros são local e produção.
- Relógio, aleatoriedade, DNS e integrações externas devem ser controláveis nos testes.
- Factories e fixtures usam dados determinísticos, não carregam segredos e nunca contêm tokens, senhas, IPs ou URLs sensíveis reais.
- Testes concorrentes devem provar constraints, locks e transações reais, não apenas comportamento de mocks.
- O banco `fake_link_testing` é criado automaticamente no primeiro boot do Postgres via `docker/postgres/init/01-create-testing-database.sql`. Volumes Postgres já existentes não reexecutam o init: recrie o volume (`docker compose down -v` e suba de novo) ou crie o banco manualmente com o script acima.

## 3. Estratégia por camada

### 3.1 Backend

O backend usa PestPHP em quatro camadas:

- Unit: Value Objects, Entities, regras puras, normalização, classificação e UseCases (com fakes de Contracts).
- Feature: endpoints, Form Requests, Resources, autenticação, autorização, rate limiting e respostas HTTP.
- Integration: PostgreSQL, Redis efêmero, Redis de fila, criptografia, concorrência, jobs, partições e transações reais.
- Architecture: gates obrigatórios para os limites do monólito modular.

Módulos de domínio ficam em `backend/modules/{Module}/` com namespace `Modules\{Module}` (ver `LARAVEL_CODE_DESIGN.md`). Eloquent Models permanecem em `Infrastructure/Persistence/Eloquent/Models` dentro de cada módulo.

Os testes de arquitetura devem falhar quando:

- Um módulo acessa diretamente Models Eloquent ou Entities de domínio de outro módulo.
- Uma dependência cruza a interface pública permitida do módulo.
- Um Controller contém regra de negócio, consulta Eloquent direta ou lógica de persistência.
- Validação HTTP fica fora de Form Requests ou representação HTTP fica fora de Resources quando aplicável.
- `Shared` passa a depender de um módulo de domínio.

### 3.2 Frontend e BFF

- Vitest cobre schemas Zod, serviços, funções puras, Route Handlers e regras do BFF.
- React Testing Library cobre componentes e fluxos pelo comportamento percebido pelo usuário.
- MSW simula os contratos HTTP, incluindo latência, erros, `ETag`, paginação e respostas fora de ordem.
- Playwright cobre os fluxos críticos completos contra a composição real.
- A implementação e os testes preservam o modelo Next.js server-first. Client Components existem apenas quando interação ou API do browser os exigem.
- Formulários usam React Hook Form com Zod e testam erros client-side e server-side, foco, submissão repetida e preservação segura de dados.
- TanStack Query não persiste cache. Os defaults testados são `staleTime` de 30 segundos, `gcTime` de 5 minutos, um retry apenas para falhas transitórias de `GET`, nenhum retry para mutations e polling de 60 segundos somente com a página visível.

### 3.3 E2E, compatibilidade e visual

- Playwright cobre login, recuperação, criação e gestão de links, redirect, conflito de edição e dashboard de analytics.
- Snapshots visuais Playwright são restritos a estados críticos e estáveis, em viewport de 360 px e em desktop. Dados, relógio, fontes, animações e rede devem estar estabilizados.
- Antes de release, BrowserStack executa as versões atual e anterior de Chrome, Edge, Firefox e Safari em desktop e iOS nas combinações aplicáveis.
- Estados dinâmicos, animações e dashboards com dados não determinísticos não entram em snapshots visuais.

### 3.4 Acessibilidade

- `axe` roda em componentes e páginas críticas sem violações conhecidas de impacto relevante.
- Uma revisão manual valida os fluxos críticos conforme WCAG 2.2 nível AA, incluindo teclado, foco visível, ordem de foco, leitores de tela, contraste, zoom, reflow a 360 px, mensagens de erro e redução de movimento.
- Falha de acessibilidade em fluxo crítico bloqueia release.

## 4. Gates de cobertura e análise

Não existe baseline tolerado ou cobertura herdada abaixo das metas. Os gates valem desde a introdução de cada módulo e não podem ser reduzidos por código novo.

| Escopo | Linhas | Branches |
| --- | ---: | ---: |
| Backend Links e Redirects | 90% | 85% |
| Auth, Analytics e BFF | 80% | 80% |
| Domínios frontend | 75% | 75% |

Cobertura numérica não substitui casos relevantes. Exclusões exigem justificativa técnica explícita e não podem remover regras de domínio, segurança ou tratamento de falhas do cálculo.

Os gates estáticos obrigatórios são:

- Laravel Pint.
- Larastan no **nível 6**, com `phpstan/phpstan-strict-rules` (fixado pelo projeto; AD-009).
- PHPMD (complexidade e acoplamento; ruleset em `backend/phpmd.xml`).
- Pest Architecture (limites do monólito modular; §3.1).
- TypeScript com `strict` habilitado.
- ESLint.
- Prettier.
- Lint da especificação OpenAPI 3.1 e detecção de breaking changes.

Não fazem parte da stack backend: PHPCS, PHP-CS-Fixer e PHP Insights. Localmente e no CI, os gates backend rodam **somente via Docker** (`make lint`, `make test-backend-coverage`, workflow `backend-quality.yml`).

Warnings novos não são aceitos como forma de aprovação do pipeline.

## 5. Contrato e OpenAPI

- A API segue design-first: a alteração começa em `docs/openapi.yaml` antes da implementação.
- O CI valida sintaxe, semântica, exemplos e breaking changes da especificação.
- Exemplos e testes de contrato são executados contra a aplicação real em teste.
- Status, headers, bodies, formatos, paginação, erros e requisitos de autenticação devem corresponder ao contrato.
- O client TypeScript usado pelo frontend é gerado da especificação e o CI falha quando o artefato estiver desatualizado.
- Mudanças incompatíveis seguem SemVer e não entram silenciosamente na versão atual da API.

## 6. Casos funcionais obrigatórios

### 6.1 Auth e conta

- Convite aceita somente o e-mail normalizado presente exatamente na allowlist e não permite variações de caixa, espaços ou aliases de e-mail para contornar a regra.
- Tentativas com e-mail ausente, não convidado, já usado ou em estado incompatível recebem resposta pública genérica, sem permitir enumeração.
- Verificação de e-mail é consumida somente por `POST` explícito. Abertura por scanner, prefetch ou `GET` não verifica nem invalida o token.
- Token de verificação é de uso único e expira em 60 minutos; token de recuperação de senha é de uso único e expira em 30 minutos. Ambos cobrem antes, no limite e depois da expiração, finalidade incorreta e consumo concorrente.
- Reenvio de verificação e solicitação de recuperação respeitam rate limit e resposta contra enumeração.
- Notificações enviadas pelo Resend não expõem existência de conta, token em log ou conteúdo sensível em telemetria.
- Senha aceita de 12 a 128 caracteres e exige minúscula, maiúscula, dígito e símbolo; entradas fora da composição são rejeitadas e o valor é persistido somente com Argon2id nos parâmetros configurados.
- Todas as transições permitidas e proibidas de `User.status` entre `pending_verification`, `active`, `suspended` e `deletion_pending` são cobertas, incluindo bloqueio de login e revogação quando o status deixa de permitir acesso.
- Aceite de termos registra versão e instante exigidos e impede ativação quando ausente ou obsoleto.
- Credencial inválida mantém mensagem e tempo observável uniformes, aplica rate limiting e não diferencia e-mail ausente de senha incorreta. Com credenciais válidas, cada `User.status` segue apenas a transição e resposta permitidas.
- Sessão completa respeita duração absoluta de 7 dias e 24 horas de inatividade. Restricted Session respeita duração absoluta de 24 horas e 1 hora de inatividade enquanto o `User` não estiver verificado.
- Logout revoga a sessão corrente. A operação de revogar todas as sessões invalida todos os tokens e sessões BFF do usuário.
- Mudança ou reset de senha revoga todas as sessões; no perfil, somente o nome pode ser alterado.
- Token Bearer usado diretamente respeita a expiração absoluta e por inatividade de seu tipo, atualiza `last_used_at` no máximo uma vez a cada 15 minutos e não estende token já expirado ou revogado.
- Tipo de token, status do User, ownership e resposta `404` genérica impedem acesso a recursos de outra conta.

### 6.2 BFF e sessão do frontend

- Route Handlers mantêm o token Bearer exclusivamente no servidor; ele não aparece em HTML, props serializadas, JavaScript, Web Storage, IndexedDB, URL, logs ou respostas ao browser.
- O token é criptografado antes de ser armazenado no Redis, com chave externa ao Redis, e falha de decriptação encerra a sessão de forma segura.
- O identificador de sessão é aleatório, opaco e validado por HMAC antes de qualquer lookup; adulteração não consulta uma sessão arbitrária.
- O cookie usa prefixo `__Host-` quando aplicável e sempre possui `HttpOnly`, `Secure`, `SameSite=Lax` e `Path=/`, sem `Domain`.
- Mutations validam `Origin` contra a allowlist e exigem double-submit CSRF com comparação segura. Ausência, divergência, replay inválido ou origem não permitida bloqueiam a operação.
- O BFF permite somente Route Handlers, métodos e destinos explicitamente conhecidos, sem atuar como proxy genérico.
- Perda ou limpeza do Redis resulta em logout seguro, remoção do cookie e ausência de fallback do token para o browser.
- Logout sempre expira o cookie, tenta remover sessão/material criptografado e revogar o token, e mede falhas best-effort quando API ou Redis estão indisponíveis.
- `returnUrl` aceita apenas caminhos internos seguros; URLs absolutas, protocol-relative, codificações ambíguas e redirects para origem externa são rejeitados.
- Em conflito `ETag`, a UI apresenta comparação compreensível, busca a versão atual e permite reaplicar explicitamente a intenção do usuário sem sobrescrita automática.
- Defaults de TanStack Query, ausência de persistência, retry e polling visível são validados com relógio e visibilidade controlados.

### 6.3 Links

- Namespace de slugs é global e case-insensitive. Alias personalizado é normalizado para minúsculas antes de validação e reserva.
- Slug automático possui exatamente 8 caracteres Base36 minúsculos, usa fonte criptograficamente segura e tenta no máximo 5 vezes após colisões controladas.
- Concorrência entre aliases equivalentes por caixa é resolvida pela constraint do PostgreSQL, sem sobrescrita ou revelação do proprietário.
- Reserva é permanente. Após exclusão do link ou da conta, permanece órfã, sem proprietário ou destino, e o redirect desse slug retorna `410`.
- A política de URL aceita somente HTTP/HTTPS com hostname público válido, rejeita literais de IP, nomes locais ou especiais, credenciais, caracteres de controle e o próprio domínio curto.
- Portas customizadas são aceitas e portas padrão redundantes são removidas durante a normalização.
- Query string e fragmento semanticamente válidos do destino são preservados pela normalização e pela criptografia; não aparecem em logs ou telemetria.
- Destino atual e histórico são criptografados com chave externa ao PostgreSQL e podem ser decriptados somente pelos caminhos autorizados.
- Criação, primeira versão, reserva e idempotência são confirmadas na mesma transação.
- `Idempotency-Key` é escopada por usuário. O fingerprint é HMAC do método, rota e payload normalizado; material de replay é criptografado.
- Repetição exata dentro da validade retorna exatamente status, headers e body originais, sem novo link. Payload normalizado diferente com a mesma chave retorna `409`.
- Atualizações de título, destino, habilitação e expiração exigem `If-Match`; ausência retorna `428` e versão obsoleta retorna `412`.
- Alteração efetiva incrementa a versão e produz novo `ETag`. No-op não incrementa versão, não cria histórico e não invalida cache sem necessidade.
- Duas atualizações concorrentes não perdem dados; somente uma confirma com o mesmo `ETag`.
- Bloqueio operacional mantém o status efetivo `blocked` apesar da intenção, edição ou expiração definida pelo proprietário, mas o link continua editável, com histórico e analytics privados visíveis.
- Edição de destino fecha a versão corrente e cria uma única nova versão na mesma transação; histórico é imutável e preserva a ordem temporal.
- Não existe endpoint de hard delete de link para o usuário; uma rota `DELETE` ausente não pode remover link ou reserva.

### 6.4 Redirect

- `GET /{slug}` e `HEAD /{slug}` resolvem o mesmo estado; `HEAD` reproduz status e headers sem body e não contabiliza clique.
- A raiz responde `302` para a landing page sem analytics. Caixa diferente e uma barra final resolvem o mesmo slug canônico.
- Qualquer percent-encoding ou segmento extra no caminho é rejeitado e não é confundido com um slug válido.
- Query string recebida na URL curta é ignorada para lookup e não é anexada ao destino.
- Link ativo e não expirado retorna `302`, `Location` exato e `Cache-Control: no-store`. Ausente e nunca reservado retorna `404`; inativo, expirado, bloqueado ou reserva órfã retorna `410`; rate limit retorna `429`.
- Cache positivo e negativo respeita TTL, expiração e invalidação após commit.
- Snapshot de destino no Redis é criptografado. Corrupção, formato desconhecido ou ausência da chave de decriptação nunca produz redirect para dados parciais.
- Cache hit válido evita PostgreSQL. Redis efêmero indisponível faz fallback para PostgreSQL e pode reconstruir o cache após recuperação.
- PostgreSQL indisponível não afeta cache hit válido; cache miss sem fonte de verdade retorna `503` sem revelar detalhes.
- Falha de decriptação do destino no cache e no PostgreSQL retorna `503`, emite alerta sanitizado e nunca retorna um `Location` inseguro.
- Produção e publicação de analytics ocorrem após a resposta. Worker parado, Redis de fila indisponível ou perda aceita do evento não muda um redirect já válido.
- Falhas de analytics são contabilizadas por métricas sem registrar IP, user-agent, referenciador completo, destino ou query string.

### 6.5 Analytics

- Classificação distingue `human`, `bot`, `preview` e `unknown` com fixtures versionadas e determinísticas.
- Derivação persiste somente a dimensão de dispositivo permitida; browser e sistema operacional não fazem parte do MVP. As categorias `desktop`, `mobile`, `tablet`, `other` e `unknown` são sempre retornadas, inclusive zeradas.
- Referenciador persiste somente hostname público normalizado. Ausente, privado ou inválido vira `direct` e não vaza path, query, fragmento ou credenciais.
- Nenhum payload, Redis, job, `failed_jobs`, banco, log ou trace contém IP bruto, user-agent bruto, URL completa do referenciador ou destino.
- HMAC usa chave diária, inclui o escopo do link e produz valor estável apenas para o mesmo link, dia UTC e entrada reduzida; muda entre links e dias.
- Somente tráfego humano pode incrementar `estimated_unique_clicks`. `bot`, `preview`, `unknown` e consultas `all` expõem Estimated Unique Click como `null` e nunca entram na deduplicação de humanos.
- Séries retornam buckets densos, inclusive zeros, no período e granularidade solicitados.
- Referrers retornam somente totais para os principais valores e agrupam o restante em `other`; breakdowns de analytics não calculam únicos.
- Partições são diárias, são pré-criadas com sete dias de antecedência e removidas após 90 dias sem bloquear ingestão válida. Agregados permanecem por 366 dias e resolução horária por 31 dias.
- Retry com o mesmo `event_id` e `occurred_at` não duplica evento, visitante ou agregado.
- Replay tardio e rebuild controlado recompõem agregados a partir de `is_estimated_unique` já persistido, sem tentar recriar HMAC ou classificação bruta.
- Evento, deduplicação humana e agregados confirmam ou revertem na mesma transação.
- Consultas respeitam ownership, intervalo, granularidade, tipo de tráfego, limites e ordenação determinística.
- Jobs de retenção e reconstrução são idempotentes e auditáveis.

### 6.6 Operations

- Comandos de suspensão de usuário, bloqueio de link, exclusão de conta, reconciliação e rebuild exigem autorização operacional explícita.
- Suspensão revoga sessões e impede novas autenticações; bloqueio interrompe redirect sem liberar slug.
- Exclusão remove dados em batches retomáveis, preserva somente a reserva permanente sem proprietário e respeita a política legal.
- Falha parcial de batch pode ser retomada sem apagar dados de outra conta nem repetir efeitos destrutivos.
- Reconciliação detecta e corrige divergências entre eventos e agregados de forma idempotente.
- Operações sobre `User` não enviam e-mail.
- Toda operação registra auditoria imutável com ator, ação, alvo interno permitido, resultado e instante, sem conteúdo sensível.

### 6.7 Telemetria e privacidade

- Redaction é testada em logs, métricas, traces, exceptions, audit logs, payloads Redis, jobs e `failed_jobs`.
- Labels de métricas e atributos de trace não aceitam slug, URL, e-mail, token, IP, user-agent, query string ou qualquer valor de alta cardinalidade proibido.
- Testes sentinela injetam valores marcadores e varrem todos os sinks de telemetria para provar sua ausência.
- Alertas usam identificadores internos e request IDs permitidos, sem reproduzir payloads sensíveis.

## 7. Concorrência e modos de falha

As suítes de integração e resiliência cobrem, no mínimo:

- Redis efêmero lento, indisponível, reiniciado ou esvaziado.
- Redis de fila lento, indisponível, reiniciado ou com perda de dados aceita; a perda de analytics deve ser mensurada e nunca bloquear redirect.
- Worker parado, retomado, duplicado ou encerrado durante um job.
- PostgreSQL lento, sem conexões, read-only ou indisponível, distinguindo cache hit de cache miss.
- Cache de destino corrompido, ciphertext adulterado, versão desconhecida ou chave ausente.
- Notificação expirada, retry duplicado do provider Resend e falha permanente sem enumeração.
- Colisão de slug, reserva concorrente, idempotência concorrente e atualização concorrente por `ETag`.
- Backup criptografado, restauração em composição limpa e validação funcional e de integridade dos dados restaurados.
- Deploy incompatível interrompido e rollback para a imagem anterior sem migração destrutiva ou perda de disponibilidade prevista.

Testes de stress procuram o limite e testes de resiliência injetam falhas. Seus resultados não são misturados ao benchmark nominal nem usados para relaxar seus critérios.

## 8. Benchmark nominal

### 8.1 Ambiente de referência

- Host de 4 vCPU, 8 GB de RAM e 200 GB de disco.
- A referência de 1 milhão de redirects por dia é tratada como benchmark, não SLA ou previsão de tráfego real.
- Todos os containers da composição de produção ativos, incluindo OpenTelemetry.
- Tráfego enviado diretamente à origem privada, passando por TLS e Nginx, sem Cloudflare.
- Banco com 100 mil links válidos e dataset determinístico.
- Distribuição 80/20 entre links populares, complementada por cauda longa determinística.
- Warmup documentado antes de coletar resultados.
- Ferramenta, configuração, seed, commit, versões, resultado e observações versionados em `tests/load/`.

### 8.2 Redirect e analytics

O cenário nominal executa 120 RPS por 15 minutos após o warmup.

Critérios cumulativos de aprovação:

- Redirect `p95 < 100 ms` e `p99 < 250 ms`.
- Erros inesperados inferiores a 0,1%.
- Atraso de analytics `p95 < 60 s`.
- Backlog produzido pelo cenário é drenado em menos de 5 minutos após o fim da carga.
- Nenhum evento ou agregado duplicado.
- CPU, memória, disco, conexões, Redis e fila não apresentam saturação sustentada.

### 8.3 API privada

O cenário executa 20 RPS por 10 minutos com a mistura:

- 70% listagem e detalhe de links.
- 20% consultas de analytics.
- 10% criação e edição de links.

Critérios cumulativos de aprovação:

- `p95 < 300 ms`.
- `p99 < 750 ms`.
- Erros inesperados inferiores a 0,1%.

### 8.4 Execução e exposição pública

- Um load smoke reduzido roda em `main` para detectar regressões grosseiras.
- O benchmark completo roda semanalmente e sob demanda, sempre em composição efêmera equivalente à referência.
- Um smoke público separado atravessa Cloudflare para validar DNS, TLS, proxy, headers e roteamento. Latência desse smoke não é misturada à meta da origem.
- OpenTelemetry permanece habilitado em todos os cenários para incluir seu custo e validar traces, métricas e redaction.

## 9. Segurança da cadeia de entrega

As verificações obrigatórias incluem:

- `composer audit` e auditoria das dependências JavaScript.
- Trivy para filesystem e imagens.
- Gitleaks no histórico e alterações.
- CodeQL semanal e sob demanda.
- Dependabot para dependências e GitHub Actions.

Vulnerabilidade alta ou crítica bloqueia release. Uma exceção exige risco documentado, responsável, mitigação, prazo curto e data de expiração; exceção vencida volta a bloquear automaticamente.

## 10. CI e artefatos

Todo pull request executa:

1. Gates backend via Docker (workflow `.github/workflows/backend-quality.yml`): Pint, Larastan nível 6, PHPMD, Pest Architecture e Pest com cobertura PCOV — paridade com `make lint` / `make test-backend-coverage`. Sem instalação de PHP/Composer no runner.
2. TypeScript strict, ESLint e Prettier (quando o job frontend estiver ativo).
3. OpenAPI lint, exemplos, contract tests, diff e verificação do client TypeScript gerado.
4. Pest unit, feature, integration e architecture com cobertura (o slice backend já cobre Architecture + cobertura no workflow acima).
5. Vitest, React Testing Library e MSW com cobertura.
6. Playwright funcional, snapshots visuais estáveis e `axe` nos fluxos críticos.
7. Auditorias de dependência, Gitleaks e Trivy aplicáveis.
8. Builds das aplicações e smoke da composição completa.

Regras de entrega:

- `main` é protegida e não aceita push direto; merge exige todos os checks obrigatórios.
- Releases seguem SemVer.
- Imagens multiarch são construídas de forma reproduzível, publicadas no GHCR e identificadas por versão e digest imutável.
- Cada release gera SBOM e assinatura Cosign verificável antes do deploy.
- O smoke da composição valida migrations, readiness, frontend, API, redirect, Redis efêmero, Redis de fila e worker.

## 11. Gates de release

Todos os itens abaixo são cumulativos e bloqueantes:

- E2E funcional aprovado.
- OpenAPI, exemplos, contract tests e client gerado sincronizados.
- Suite de segurança e análise estática sem bloqueadores.
- WCAG 2.2 AA validada por `axe` e revisão manual nos fluxos críticos.
- Benchmark nominal aprovado no ambiente de referência.
- Scans de privacidade e redaction sem dados proibidos.
- Backup recente restaurado e validado em ambiente limpo.
- Alertas críticos disparados e recebidos em teste controlado.
- Revisão jurídica concluída para termos, privacidade, cookies, retenção e exclusão.
- Domínios finais, DNS, TLS, Resend e Cloudflare validados.
- Runbooks de deploy, rollback, incidentes, revogação de segredos, restauração, fila e bloqueio operacional exercitados.
- Matriz BrowserStack pré-release aprovada.

Nenhum gate é presumido por aprovação anterior quando código, infraestrutura, contrato ou dependência relevante tiver mudado.
