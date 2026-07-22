# Docker Foundation Specification

**Status:** Fechada — assunções confirmadas 2026-07-21. Design aprovado; em Tasks.

## Problem Statement

O Fake Link exige que desenvolvimento, testes, análise, build e operação ocorram exclusivamente via Docker, sem caminho local alternativo suportado. Hoje o repositório não possui `docker/`, `docker-compose.yml` nem imagens — impossibilitando validar integração com PostgreSQL, as duas instâncias Redis, HTTPS local e os perfis de teste/observabilidade/benchmark descritos na arquitetura.

Sem essa fundação, a Fase 0 do roadmap fica bloqueada: nenhum desenvolvedor consegue subir o ambiente, executar health checks ou preparar CI/smoke da composição.

## Goals

- [ ] Um desenvolvedor sobe o stack completo de desenvolvimento com um fluxo documentado e único, usando apenas Docker e Docker Compose.
- [ ] A composição reflete a topologia de produção em escala reduzida: Nginx, Next.js, Laravel, PostgreSQL, Redis efêmero, Redis de fila, scheduler e workers.
- [ ] HTTPS local em `app.localhost` e `go.localhost` funciona com certificado de desenvolvimento, sem relaxar propriedades de cookie (`Secure`, `HttpOnly`, `SameSite`).
- [ ] Perfis Compose separam desenvolvimento, testes, observabilidade e benchmark sem ambiente de staging permanente.
- [ ] Health checks, variáveis de ambiente exemplificadas e limites operacionais básicos estão definidos desde o início.

## Out of Scope

Explicitamente excluído desta feature. Documentado para evitar scope creep.

| Feature | Reason |
| --- | --- |
| Scaffold completo de Laravel e Next.js (módulos, rotas de negócio, BFF) | Entregável separado da Fase 0; esta SPEC cobre apenas infraestrutura container e stubs mínimos de readiness |
| Provisionamento Ansible, Cloudflare, Tailscale e deploy em VM | Fase 4 / infraestrutura de produção |
| Segredos SOPS/age e rotação operacional | Fase 4; apenas placeholders de variáveis nesta fase |
| Stack completa de observabilidade em produção (Collector, Prometheus, Tempo, Loki, Grafana) | Produção habilita stack completa; aqui apenas perfil de desenvolvimento/teste com serviços mínimos ou stub |
| Workflows GitHub Actions (build multiarch, SBOM, Cosign, CI) | Preparação de Dockerfiles multi-stage entra no escopo; pipelines CI são feature separada |
| Domínios registráveis finais de produção | Bloqueador de deploy; local usa `app.localhost` / `go.localhost` |
| HSTS | Rejeitado permanentemente (ADR 0006) |
| SQLite ou substituto de PostgreSQL/Redis | Proibido pelo projeto |
| Instalação local de PHP, Node, Composer ou pnpm fora de container | Proibido como caminho suportado |

---

## Assumptions & Open Questions

Toda ambiguidade foi resolvida em 2026-07-21. Detalhes expandidos em [context.md](./context.md).

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Versões exatas da stack | Arquivo canônico `docker/versions.env` — ver tabela abaixo | Roadmap Fase 0; stable-supported jul/2026 (AD-005) | y |
| Geração de certificados TLS locais | Script OpenSSL versionado em `docker/nginx/certs/`; dev importa CA local uma vez | Reproduzível sem mkcert no host (AD-001) | y |
| Stubs de aplicação para smoke inicial | `GET /health` → `200` JSON `{"status":"ok"}` em backend e frontend; workers/scheduler Laravel mínimos | Readiness antes do código de domínio | y |
| Número de workers no perfil `dev` | 1 analytics + 1 notification; `benchmark`/`prod` usa 2+1 | Menos RAM local (AD-002) | y |
| Publicação de portas sensíveis | Dev publica PG/Redis no host; `test` e prod **não** publicam | DX local vs hardening prod (AD-004) | y |
| Swagger UI | Profile `docs`; `https://app.localhost/docs` via Nginx | Roadmap Fase 0 | y |
| Estrutura de diretórios | `docker/nginx/`, `docker/php/`, `docker/node/`, `docker/postgres/`, `docker/redis/`, `docker/scripts/` | Convenção modular | y |
| Arquivo Compose de produção | `docker-compose.prod.yml` — recursos, restart, sem bind mounts, workers 2+1, observabilidade completa (P2) | ADR 0005; `architecture.md` §11 | y |
| Comandos únicos documentados | `Makefile` na raiz; README referencia targets (AD-003) | Roadmap: comandos únicos via Docker | y |

**Open questions:** none — all resolved or logged above.

### Pin de versões (`docker/versions.env`)

| Variável | Valor | Fonte (jul/2026) |
| --- | --- | --- |
| `PHP_VERSION` | `8.4.23` | PHP active support; Laravel 13 compat (8.3–8.5) |
| `LARAVEL_VERSION` | `13.16.1` | Laravel 13.x security support até 2028-03 |
| `COMPOSER_VERSION` | `2.10.2` | getcomposer.org stable (jul/2026) |
| `NODE_VERSION` | `24.18.0` | Node.js 24 Active LTS latest |
| `PNPM_VERSION` | `11.15.1` | npm registry latest; Corepack no Dockerfile frontend |
| `NEXT_VERSION` | `16.2.11` | Next.js 16 Active LTS (security release jul/2026) |
| `POSTGRES_VERSION` | `18.4` | Docker Hub `postgres:18.4` |
| `REDIS_VERSION` | `8.8.0` | Docker Hub `redis:8.8.0` |
| `NGINX_VERSION` | `1.30.4` | Docker Hub `nginx:1.30.4` (stable, não mainline) |

Bump de patch de segurança: atualizar `docker/versions.env` + rebuild; minor/major exige revisão de AD e docs.

---

## User Stories

### P1: Ambiente de desenvolvimento HTTPS reproduzível ⭐ MVP

**User Story**: Como desenvolvedor, quero subir toda a aplicação e dependências com Docker Compose para trabalhar sem instalar PHP, Node ou bancos localmente.

**Why P1**: Bloqueia literalmente todo o restante da Fase 0; é o critério de saída principal do roadmap.

**Acceptance Criteria**:

1. WHEN o desenvolvedor executa o comando documentado de bootstrap (build + up do profile padrão) THEN a composição SHALL subir os serviços `nginx`, `frontend`, `backend`, `postgres`, `redis-ephemeral`, `redis-queue`, `scheduler`, `analytics-worker` e `notification-worker` sem erro de configuração.
2. WHEN todos os containers estão `healthy` THEN `GET https://app.localhost/health` SHALL retornar HTTP 200 com body JSON `{"status":"ok"}`.
3. WHEN todos os containers estão `healthy` THEN `GET https://go.localhost/health` SHALL retornar HTTP 200 com body JSON `{"status":"ok"}`.
4. WHEN o Nginx recebe tráfego para `app.localhost` THEN ele SHALL rotear interface/BFF/API (`/api/v1`, `/docs` quando profile ativo) para `frontend` e/ou `backend` conforme mapa de rotas documentado.
5. WHEN o Nginx recebe tráfego para `go.localhost` THEN ele SHALL encaminhar somente métodos/caminhos permitidos do Short host para o backend Laravel (redirect surface).
6. WHEN cookies são emitidos pelo frontend em desenvolvimento THEN eles SHALL manter `Secure`, `HttpOnly` e `SameSite=Lax` — o ambiente local não relaxa flags de segurança.
7. WHEN o desenvolvedor consulta `.env.example` THEN ele SHALL encontrar todas as variáveis necessárias para subir a composição, sem segredos reais commitados.
8. WHEN `docker compose config` é executado THEN a saída SHALL ser válida e SHALL refletir dois hosts Redis distintos (`redis-ephemeral` e `redis-queue`) com configurações de persistência e política de memória diferentes conforme `architecture.md` §9.

**Independent Test**: Clonar o repositório em máquina limpa com Docker instalado, copiar `.env.example` → `.env`, executar bootstrap documentado, aguardar health checks e obter 200 em ambos hosts HTTPS.

---

### P1: Datastores e filas com comportamento de produção em escala local ⭐ MVP

**User Story**: Como desenvolvedor, quero PostgreSQL e os dois Redis com políticas corretas para que testes de integração reflitam falhas reais de cache e fila.

**Why P1**: SQLite e Redis único são proibidos; comportamento errado aqui invalida Auth, Redirect e Analytics depois.

**Acceptance Criteria**:

1. WHEN o serviço `postgres` inicia THEN ele SHALL persistir dados em volume nomeado e SHALL expor health check baseado em readiness do PostgreSQL.
2. WHEN o serviço `redis-ephemeral` inicia THEN ele SHALL ter persistência desabilitada e política de eviction habilitada.
3. WHEN o serviço `redis-queue` inicia THEN ele SHALL usar AOF e política `noeviction`.
4. WHEN o backend e workers iniciam THEN variáveis de conexão SHALL apontar para instâncias separadas de Redis (não URL única compartilhada por acidente).
5. WHEN o comando documentado de migration é executado via container THEN ele SHALL aplicar migrations no PostgreSQL da composição.
6. WHEN o profile padrão está ativo THEN o scheduler SHALL executar `schedule:work` (ou equivalente Laravel) e os workers SHALL executar `queue:work` nas filas `analytics` e `notifications`.

**Independent Test**: Subir composição, executar migration, enfileirar job de teste em cada fila, verificar consumo e reiniciar `redis-ephemeral` confirmando perda aceita de cache/sessão sem perda de jobs persistidos no `redis-queue`.

---

### P1: Health checks e encerramento gracioso ⭐ MVP

**User Story**: Como operador local, quero health checks confiáveis e shutdown ordenado para que `docker compose up` e deploys futuros detectem falhas cedo.

**Why P1**: Critério de saída da Fase 0 exige composição com todos os health checks passando.

**Acceptance Criteria**:

1. WHEN qualquer serviço de aplicação ou datastore está definido no Compose THEN ele SHALL declarar `healthcheck` com intervalo, timeout, retries e `start_period` adequados.
2. WHEN um serviço dependente inicia THEN ele SHALL usar `depends_on` com condição `service_healthy` para serviços críticos (postgres, redis, backend quando aplicável).
3. WHEN o Compose recebe SIGTERM THEN containers SHALL encerrar dentro de `stop_grace_period` configurado sem corromper AOF do Redis de fila.
4. WHEN um health check falha após retries THEN `docker compose ps` SHALL reportar o serviço como `unhealthy`.

**Independent Test**: Subir stack, derrubar PostgreSQL manualmente, observar serviços dependentes ficarem unhealthy; restaurar PG e confirmar recuperação.

---

### P2: Perfis Compose para testes, documentação e benchmark

**User Story**: Como mantenedor de CI, quero perfis isolados para testes efêmeros, Swagger UI e carga sem poluir o ambiente de desenvolvimento diário.

**Why P2**: `docs/testing.md` exige composições efêmeras para CI/benchmark; não bloqueia primeiro `up` local.

**Acceptance Criteria**:

1. WHEN o profile `test` é ativado THEN a composição SHALL usar configuração isolada (banco/filas/volumes de teste ou sufixo `_test`) e SHALL NOT reutilizar volumes do profile padrão.
2. WHEN o profile `docs` é ativado THEN Swagger UI SHALL ficar acessível em `https://app.localhost/docs` via Nginx.
3. WHEN o profile `benchmark` é ativado THEN a composição SHALL permitir escalar para 2 `analytics-worker` e SHALL expor documentação de variáveis para execução de carga conforme `docs/testing.md` §8.
4. WHEN o profile `observability` é ativado em desenvolvimento THEN serviços mínimos (ex.: OpenTelemetry Collector stub ou stack reduzida) SHALL iniciar sem publicar backends sensíveis na internet pública.

**Independent Test**: Executar `docker compose --profile test up` em CI efêmero, rodar suite de smoke, destruir volumes; repetir com `--profile docs` e validar UI de documentação.

---

### P2: Imagens reproduzíveis e Compose de produção

**User Story**: Como responsável por release, quero Dockerfiles multi-stage e `docker-compose.prod.yml` alinhados à VM de referência para builds multiarch futuros.

**Why P2**: Roadmap exige preparação para GHCR/SBOM/Cosign; produção usa mesma topologia com hardening.

**Acceptance Criteria**:

1. WHEN as imagens `backend` e `frontend` são construídas THEN Dockerfiles SHALL ser multi-stage, SHALL NOT incluir segredos em layers e SHALL pinar versões base via argumentos ou arquivo de versões.
2. WHEN `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` é executado THEN serviços SHALL incluir limites de CPU/memória, `restart: unless-stopped` e SHALL NOT montar código-fonte do host para runtime de aplicação.
3. WHEN imagens são construídas para `linux/amd64` e `linux/arm64` THEN o build SHALL completar sem alteração manual de Dockerfile (via `buildx` documentado).
4. WHEN serviços de datastore rodam em produção THEN portas PostgreSQL/Redis SHALL NOT ser publicadas no host.

**Independent Test**: Build local multiarch (ou CI simulado), subir compose prod em máquina de teste, verificar ausência de bind mounts de código e limites aplicados.

---

### P3: Ergonomia e documentação operacional

**User Story**: Como novo contribuidor, quero comandos padronizados e troubleshooting claro para operações rotineiras via Docker.

**Why P3**: Melhora DX; não impede entrega funcional.

**Acceptance Criteria**:

1. WHEN o desenvolvedor executa `make help` THEN ele SHALL ver lista de targets para up, down, logs, shell, migrate, test, lint e trust-ca via containers.
2. WHEN o README é consultado THEN ele SHALL referenciar o Makefile e conter seção "Ambiente Docker" com pré-requisitos (Docker, Compose, importação da CA local), bootstrap (`make up`), URLs locais e troubleshooting (porta ocupada, certificado não confiável, health check pendente).
3. WHEN seeds determinísticos são executados THEN eles SHALL estar restritos a local/CI via profile ou flag explícita, nunca em produção.

**Independent Test**: Desenvolvedor sem contexto prévio segue README e completa bootstrap em ≤ 15 minutos em máquina com Docker funcional.

---

## Edge Cases

- WHEN a porta 443 (ou portas mapeadas) já está em uso no host THEN o bootstrap SHALL falhar com mensagem explícita indicando conflito de porta.
- WHEN certificados de desenvolvimento não existem ou expiraram THEN `make trust-ca` (ou target documentado) SHALL regenerá-los via script OpenSSL em `docker/nginx/certs/` sem quebrar silenciosamente o TLS.
- WHEN o profile padrão (`dev`) está ativo THEN PostgreSQL e ambos Redis SHALL publicar portas mapeadas no host com valores fixos documentados no `.env.example`.
- WHEN os profiles `test` ou produção estão ativos THEN PostgreSQL e ambos Redis SHALL NOT publicar portas no host.
- WHEN `redis-queue` atinge limite de memória THEN política `noeviction` SHALL causar erro visível no worker/enfileiramento, não eviction silenciosa de jobs.
- WHEN o backend inicia antes do PostgreSQL estar ready THEN health check SHALL falhar até PG healthy; serviços dependentes SHALL NOT marcar healthy prematuramente.
- WHEN variáveis obrigatórias estão ausentes no `.env` THEN Compose ou entrypoint SHALL falhar na inicialização com mensagem indicando variável faltante (não stack trace obscuro).
- WHEN o host não resolve `app.localhost` / `go.localhost` THEN documentação SHALL indicar requisito (mDNS, `/etc/hosts` ou suporte nativo do SO).
- WHEN HSTS é sugerido ou adicionado em config Nginx THEN a configuração SHALL permanecer ausente de `Strict-Transport-Security` (decisão permanente ADR 0006).

---

## Implicit-Requirement Dimensions

| Dimension | Resolution |
| --- | --- |
| Input validation & bounds | Variáveis de ambiente validadas na inicialização; portas e limites de recurso documentados; versões pinadas |
| Failure / partial-failure states | Health checks unhealthy; depends_on impede cascata silenciosa; Redis efêmero pode perder cache; fila persiste via AOF |
| Idempotency / retry / duplicate handling | `docker compose up` idempotente; migrations via comando explícito; workers com retry Laravel (config stub) |
| Auth boundaries & rate limits | N/A nesta feature — rate limiting é responsabilidade da aplicação; Compose não expõe datastores publicamente em prod |
| Concurrency / ordering | Ordem de startup via health checks; workers independentes por fila |
| Data lifecycle / expiry | Volumes nomeados para PG e redis-queue; redis-ephemeral sem persistência; volumes de teste descartáveis |
| Observability | Profile observability opcional; logs stdout/stderr; hooks para OTel collector (stub P2) |
| External-dependency failure | Falha de build de imagem ou pull bloqueia startup com erro claro; sem fallback para instalação local |
| State-transition integrity | N/A — infraestrutura sem máquina de estados de domínio |

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| DOCKER-01 | P1: Dev HTTPS | Tasks T14,T15,T18 | Mapped |
| DOCKER-02 | P1: Dev HTTPS | Tasks T7,T11,T12,T15,T18 | Mapped |
| DOCKER-03 | P1: Dev HTTPS | Tasks T8,T9,T10,T15,T18 | Mapped |
| DOCKER-04 | P1: Dev HTTPS | Tasks T7,T15,T18 | Mapped |
| DOCKER-05 | P1: Dev HTTPS | Tasks T8,T9,T10,T18 | Mapped |
| DOCKER-06 | P1: Dev HTTPS | Tasks T3,T6,T11,T18 | Mapped |
| DOCKER-07 | P1: Dev HTTPS | Tasks T1,T3,T16,T17 | Mapped |
| DOCKER-08 | P1: Dev HTTPS | Tasks T2,T13,T18 | Mapped |
| DOCKER-09 | P1: Datastores | Tasks T13,T18 | Mapped |
| DOCKER-10 | P1: Datastores | Tasks T2,T13,T18 | Mapped |
| DOCKER-11 | P1: Datastores | Tasks T2,T13,T18 | Mapped |
| DOCKER-12 | P1: Datastores | Tasks T9,T14,T18 | Mapped |
| DOCKER-13 | P1: Datastores | Tasks T17,T18 | Mapped |
| DOCKER-14 | P1: Datastores | Tasks T14,T18 | Mapped |
| DOCKER-15 | P1: Health checks | Tasks T4,T13,T14,T15 | Mapped |
| DOCKER-16 | P1: Health checks | Tasks T14,T15 | Mapped |
| DOCKER-17 | P1: Health checks | Tasks T14,T15 | Mapped |
| DOCKER-18 | P1: Health checks | Tasks T15,T18 | Mapped |
| DOCKER-19 | P2: Profiles | Tasks T19 | Mapped |
| DOCKER-20 | P2: Profiles | Tasks T20 | Mapped |
| DOCKER-21 | P2: Profiles | Tasks T21 | Mapped |
| DOCKER-22 | P2: Profiles | Tasks T22 | Mapped |
| DOCKER-23 | P2: Prod images | Tasks T23,T24 | Mapped |
| DOCKER-24 | P2: Prod images | Tasks T23 | Mapped |
| DOCKER-25 | P2: Prod images | Tasks T24 | Mapped |
| DOCKER-26 | P2: Prod images | Tasks T23 | Mapped |
| DOCKER-27 | P3: Ergonomia | Tasks T17,T25 | Mapped |
| DOCKER-28 | P3: Ergonomia | Tasks T25 | Mapped |
| DOCKER-29 | P3: Ergonomia | Tasks T25 | Mapped |

**Coverage:** 29 total, 29 mapped to tasks, 0 unmapped ✅

### Mapeamento AC → ID

| AC (Story) | Requirement IDs |
| --- | --- |
| P1 Dev #1 | DOCKER-01 |
| P1 Dev #2 | DOCKER-02 |
| P1 Dev #3 | DOCKER-03 |
| P1 Dev #4 | DOCKER-04 |
| P1 Dev #5 | DOCKER-05 |
| P1 Dev #6 | DOCKER-06 |
| P1 Dev #7 | DOCKER-07 |
| P1 Dev #8 | DOCKER-08 |
| P1 Datastores #1 | DOCKER-09 |
| P1 Datastores #2 | DOCKER-10 |
| P1 Datastores #3 | DOCKER-11 |
| P1 Datastores #4 | DOCKER-12 |
| P1 Datastores #5 | DOCKER-13 |
| P1 Datastores #6 | DOCKER-14 |
| P1 Health #1 | DOCKER-15 |
| P1 Health #2 | DOCKER-16 |
| P1 Health #3 | DOCKER-17 |
| P1 Health #4 | DOCKER-18 |
| P2 Profiles #1–4 | DOCKER-19 – DOCKER-22 |
| P2 Prod #1–4 | DOCKER-23 – DOCKER-26 |
| P3 Ergonomia #1–3 | DOCKER-27 – DOCKER-29 |

---

## Success Criteria

- [ ] Desenvolvedor novo sobe ambiente HTTPS local e obtém smoke 200 em `app.localhost` e `go.localhost` usando apenas Docker (critério de saída Fase 0).
- [ ] `docker compose ps` reporta todos os serviços P1 como `healthy` após bootstrap padrão.
- [ ] Dois Redis com políticas distintas verificáveis por configuração inspecionável (`redis-cli CONFIG GET` ou equivalente documentado).
- [ ] Nenhum segredo real commitado; `.env.example` completo e `.env` gitignored.
- [ ] Documentação em português descreve bootstrap, perfis e troubleshooting.
- [ ] HSTS ausente em toda configuração Nginx gerada.

---

## Referências do projeto

- [context.md](./context.md) — decisões fechadas com o usuário
- [design.md](./design.md) — arquitetura Compose, Nginx e componentes
- [docs/architecture.md](../../../docs/architecture.md) — §3 topologia, §9 Redis, §11 Docker Compose
- [docs/roadmap.md](../../../docs/roadmap.md) — Fase 0 entregáveis e critérios de saída
- [docs/testing.md](../../../docs/testing.md) — §2 ambiente container-only, §8 benchmark
- [docs/decisions.md](../../../docs/decisions.md) — stack e restrições
- [AGENTS.md](../../../AGENTS.md) — stack obrigatória e módulos
