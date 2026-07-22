# Docker Foundation Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/docker-foundation/design.md`  
**Status**: Approved — Execute em andamento (2026-07-21)

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (container-only), §10 (smoke da composição). Repositório greenfield — sem testes existentes; matriz deriva dos ACs P1/P2 e das camadas definidas no design.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Compose smoke / infra validation | integration | Todos os ACs P1 verificáveis via script: health HTTPS em ambos hosts, `docker compose config` por profile, dois Redis com políticas distintas, depends_on/healthcheck, volumes de teste isolados | `tests/smoke/**/*.sh`, `tests/compose/**/*.sh` | `make test` |
| Backend stub routes (`/health`, Short host stubs) | feature (Pest) | GET `/health` → 200 JSON; stubs `/`, `/robots.txt`, `/{slug}` respondem conforme design (smoke HTTP interno) | `backend/tests/Feature/**/*.php` | `make test-backend` |
| Frontend stub route (`/health`) | unit (Vitest) | GET `/health` → 200 `{"status":"ok"}` | `frontend/**/*.test.ts` | `make test-frontend` |
| Dockerfiles / Nginx / Redis configs / scripts / Makefile | none | — (build gate + smoke integration cobre comportamento) | — | build gate only |
| Prod override (`docker-compose.prod.yml`) | integration | `docker compose -f ... -f ... config` válido; sem bind mounts de código; datastores sem `ports:` | `tests/compose/prod-config.sh` | `make test` |

## Gate Check Commands

> Generated from codebase — confirm before Execute. Targets serão criados em T17; comandos abaixo são o contrato alvo.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com Pest/Vitest unitários (T10, T12) | `make test-backend && make test-frontend` |
| Full | Após tasks com smoke/compose integration (T18+) | `make test` |
| Build | Após tasks só de config/infra (T1–T8, T16, T23–T24) | `docker compose --env-file docker/versions.env config && make build` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Docker base e imagens

Fundamentos versionados, configs de datastore e Dockerfiles.

```
T1 → T2 → T3 → T4 → T5 → T6 → T7 → T8
```

### Phase 2: Application stubs

Stubs mínimos Laravel e Next.js com testes co-localizados.

```
T9 → T10 → T11 → T12
```

### Phase 3: Compose dev P1 (MVP)

Stack completo de desenvolvimento com health checks, Makefile e smoke P1.

```
T13 → T14 → T15 → T16 → T17 → T18
```

### Phase 4: Compose profiles P2

Perfis `test`, `docs`, `benchmark` e `observability`.

```
T19 → T20 → T21 → T22
```

### Phase 5: Produção, multiarch e documentação P2/P3

Override prod, build multiarch e README operacional.

```
T23 → T24 → T25
```

---

## Task Breakdown

### T1: Pin de versões e skeleton `docker/`

**What**: Criar `docker/versions.env` com pins AD-005 e diretórios vazios documentados (`nginx/`, `php/`, `node/`, `postgres/`, `redis/`, `scripts/`).  
**Where**: `docker/versions.env`, `.gitignore` updates para certs leaf  
**Depends on**: None  
**Reuses**: AD-005 em `.specs/STATE.md`, tabela em `spec.md` §Pin  
**Requirement**: DOCKER-07 (base para build reproduzível)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `docker/versions.env` contém todas as variáveis da spec (PHP, Laravel, Composer, Node, pnpm, Next, Postgres, Redis, Nginx, Swagger UI)
- [x] Estrutura de diretórios conforme `design.md` §Layout
- [x] Gate check passes: `test -f docker/versions.env && grep -q PHP_VERSION docker/versions.env`

**Tests**: none  
**Gate**: build

---

### T2: Configurações Redis dual-instance

**What**: Criar `docker/redis/ephemeral.conf` e `docker/redis/queue.conf` com políticas locked do design.  
**Where**: `docker/redis/ephemeral.conf`, `docker/redis/queue.conf`  
**Depends on**: T1  
**Reuses**: `docs/architecture.md` §9, design §Redis  
**Requirement**: DOCKER-08, DOCKER-10, DOCKER-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `ephemeral.conf`: `save ""`, `appendonly no`, `maxmemory-policy allkeys-lru`
- [x] `queue.conf`: `appendonly yes`, `appendfsync everysec`, `maxmemory-policy noeviction`
- [x] Gate check passes: `grep -q noeviction docker/redis/queue.conf && grep -q allkeys-lru docker/redis/ephemeral.conf`

**Tests**: none  
**Gate**: build

---

### T3: Scripts TLS e validação de ambiente

**What**: Implementar `generate-dev-certs.sh`, `validate-env.sh` e `trust-ca.sh` (instruções por SO).  
**Where**: `docker/scripts/`  
**Depends on**: T1  
**Reuses**: AD-001, design §Fluxo bootstrap  
**Requirement**: DOCKER-06, DOCKER-07, edge case certificados ausentes

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `generate-dev-certs.sh` gera CA + certs para `app.localhost` e `go.localhost` em `docker/nginx/certs/`
- [x] `validate-env.sh` falha com lista clara se variável obrigatória ausente
- [x] `trust-ca.sh` documenta import da CA (macOS/Linux)
- [x] Scripts são executáveis e idempotentes
- [x] Gate check passes: `bash -n docker/scripts/*.sh`

**Tests**: none  
**Gate**: build

---

### T4: Dockerfile PHP-FPM e runtime config

**What**: Multi-stage Dockerfile PHP (base/dev/prod), `php.ini`, `www.conf` com FPM ping, `docker-entrypoint.sh`.  
**Where**: `docker/php/`  
**Depends on**: T1  
**Reuses**: design §Backend PHP-FPM  
**Requirement**: DOCKER-15 (healthcheck FPM ping)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Dockerfile consome `PHP_VERSION` de `versions.env`
- [x] Targets `dev` (bind-mount friendly) e `prod` (sem dev deps)
- [x] `www.conf`: `ping.path = /ping`, `ping.response = pong`
- [x] Gate check passes: `docker compose --env-file docker/versions.env build backend` (após T13 wiring mínimo ou build isolado `docker build -f docker/php/Dockerfile`)

**Tests**: none  
**Gate**: build

---

### T5: Dockerfile Node.js frontend

**What**: Multi-stage Dockerfile Node (base/dev/prod) com Corepack + pnpm pin.  
**Where**: `docker/node/Dockerfile`  
**Depends on**: T1  
**Reuses**: design §Frontend  
**Requirement**: DOCKER-02 (upstream health)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Consome `NODE_VERSION`, `PNPM_VERSION`, `NEXT_VERSION`
- [x] Target dev: `pnpm dev`; target prod: `pnpm build && pnpm start`
- [x] Gate check passes: build de imagem frontend bem-sucedido

**Tests**: none  
**Gate**: build

---

### T6: Dockerfile Nginx e config global

**What**: Dockerfile Nginx + `conf.d/00-global.conf` (headers, sem HSTS, request ID).  
**Where**: `docker/nginx/Dockerfile`, `docker/nginx/conf.d/00-global.conf`  
**Depends on**: T1, T3  
**Reuses**: ADR 0006, design §Headers globais  
**Requirement**: DOCKER-06, edge case HSTS ausente

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Imagem estende `nginx:${NGINX_VERSION}`
- [x] `00-global.conf` não contém `Strict-Transport-Security`
- [x] Gate check passes: `! grep -ri strict-transport-security docker/nginx/`

**Tests**: none  
**Gate**: build

---

### T7: Nginx vhost `app.localhost`

**What**: Config TLS e roteamento App host (`/health`, `/api/v1`, `/docs`, catch-all → frontend).  
**Where**: `docker/nginx/conf.d/app.localhost.conf`  
**Depends on**: T6  
**Reuses**: design §Mapa rotas app.localhost  
**Requirement**: DOCKER-02, DOCKER-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] TLS 443 + redirect 80→443
- [x] `/health` → `frontend:3000/health`
- [x] `/api/v1/*` → FastCGI backend
- [x] `/*` → frontend
- [x] Gate check passes: `nginx -t` via container build ou lint de sintaxe documentado

**Tests**: none  
**Gate**: build

---

### T8: Nginx vhost `go.localhost`

**What**: Config TLS e allowlist Short host conforme `docs/security.md`.  
**Where**: `docker/nginx/conf.d/go.localhost.conf`  
**Depends on**: T6  
**Reuses**: design §Mapa rotas go.localhost, `docs/security.md` §2  
**Requirement**: DOCKER-03, DOCKER-05

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `/health` → backend FastCGI
- [x] Allowlist: `/`, `/robots.txt`, slug regex `^[a-z0-9-]+$`
- [x] Métodos mutáveis rejeitados no Nginx
- [x] Gate check passes: revisão estática + `nginx -t` quando imagem disponível

**Tests**: none  
**Gate**: build

---

### T9: Scaffold Laravel API stub

**What**: Laravel 13 API Only mínimo com rotas `/health`, `/robots.txt`, `/`, `/{slug}` stub.  
**Where**: `backend/`  
**Depends on**: T4  
**Reuses**: design §Stubs backend  
**Requirement**: DOCKER-02, DOCKER-03, DOCKER-05, DOCKER-12–14 (drivers pgsql/redis)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `GET /health` → `200 {"status":"ok"}`
- [x] Stubs Short host retornam respostas mínimas (302 raiz, 200 robots, slug stub)
- [x] `.env` template compatível com variáveis Compose (DB_*, REDIS_*, REDIS_QUEUE_*)
- [x] Migrations vazias ou mínimas aplicáveis
- [x] Gate check passes: `make build` (backend image)

**Tests**: none (testes em T10)  
**Gate**: build

---

### T10: Pest feature tests — backend stub routes

**What**: Testes Pest para `/health` e rotas stub do Short host.  
**Where**: `backend/tests/Feature/HealthTest.php`, `backend/tests/Feature/ShortHostStubTest.php`  
**Depends on**: T9  
**Reuses**: `docs/testing.md` §3.1 Feature layer  
**Requirement**: DOCKER-02, DOCKER-03, DOCKER-05

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Pest cobre GET `/health` → 200 JSON spec
- [x] Pest cobre stubs `/`, `/robots.txt`, `/{slug}` (happy paths)
- [x] Gate check passes: `make test-backend`
- [x] Test count: ≥3 tests pass (no silent deletions)

**Tests**: feature (Pest)  
**Gate**: quick

---

### T11: Scaffold Next.js stub com `/health`

**What**: Next.js 16 App Router mínimo com `app/health/route.ts` e config cookies `Secure` preparada.  
**Where**: `frontend/`  
**Depends on**: T5  
**Reuses**: design §Stubs frontend  
**Requirement**: DOCKER-02, DOCKER-06

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `GET /health` → `200 {"status":"ok"}`
- [x] `pnpm-lock.yaml` presente; Corepack/pnpm pin
- [x] Cookie defaults não relaxam `Secure`/`HttpOnly`/`SameSite`
- [x] Gate check passes: `make build` (frontend image)

**Tests**: none (testes em T12)  
**Gate**: build

---

### T12: Vitest unit test — frontend `/health`

**What**: Teste Vitest para route handler `/health`.  
**Where**: `frontend/app/health/route.test.ts` (ou padrão co-localizado do projeto)  
**Depends on**: T11  
**Reuses**: `docs/testing.md` §3.2 Vitest  
**Requirement**: DOCKER-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Vitest asserta status 200 e body `{"status":"ok"}`
- [x] Gate check passes: `make test-frontend`
- [x] Test count: ≥1 test pass

**Tests**: unit (Vitest)  
**Gate**: quick

---

### T13: Compose — datastores

**What**: Serviços `postgres`, `redis-ephemeral`, `redis-queue` com volumes, healthchecks e portas dev (AD-004).  
**Where**: `docker-compose.yml` (partial)  
**Depends on**: T2  
**Reuses**: design §Postgres, §Redis, AD-004  
**Requirement**: DOCKER-08, DOCKER-09, DOCKER-10, DOCKER-11, DOCKER-15

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Volumes nomeados PG e redis-queue; ephemeral sem persistência
- [x] Healthchecks conforme matriz do design
- [x] Dev: ports 5432, 6379, 6380 mapeados via `.env`
- [x] Gate check passes: `docker compose --env-file docker/versions.env config`

**Tests**: none  
**Gate**: build

---

### T14: Compose — backend, scheduler e workers

**What**: Serviços `backend`, `scheduler`, `analytics-worker`, `notification-worker` (1+1 dev).  
**Where**: `docker-compose.yml`  
**Depends on**: T4, T9, T13  
**Reuses**: AD-002, design §Workers  
**Requirement**: DOCKER-01, DOCKER-12, DOCKER-14, DOCKER-15, DOCKER-16

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Variáveis Redis separadas (ephemeral vs queue)
- [x] Commands artisan conforme design
- [x] `depends_on` + `service_healthy` para PG e redis-queue
- [x] `stop_grace_period: 30s` nos workers
- [x] Gate check passes: `docker compose config`

**Tests**: none  
**Gate**: build

---

### T15: Compose — frontend, nginx e grafo completo

**What**: Serviços `frontend` e `nginx` com healthchecks HTTPS dual-vhost e depends_on final.  
**Where**: `docker-compose.yml`  
**Depends on**: T6, T7, T8, T11, T14  
**Reuses**: design §Healthchecks matriz, §Grafo depends_on  
**Requirement**: DOCKER-01, DOCKER-02, DOCKER-03, DOCKER-04, DOCKER-15, DOCKER-16, DOCKER-17, DOCKER-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Nginx healthcheck curl em ambos vhosts
- [x] Frontend depends_on backend healthy
- [x] Nginx depends_on backend + frontend healthy
- [x] Gate check passes: `docker compose config`

**Tests**: none  
**Gate**: build

---

### T16: `.env.example` completo

**What**: Arquivo `.env.example` com todos os grupos obrigatórios do design.  
**Where**: `.env.example`, `.gitignore`  
**Depends on**: T13, T14, T15  
**Reuses**: design §Variáveis de ambiente  
**Requirement**: DOCKER-07

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Grupos Compose, Postgres, App, URLs, Redis, Ports dev presentes
- [x] Sem segredos reais; placeholders seguros
- [x] `validate-env.sh` passa com `.env.example` copiado
- [x] Gate check passes: `cp .env.example .env.test && docker/scripts/validate-env.sh`

**Tests**: none  
**Gate**: build

---

### T17: Makefile — interface operacional

**What**: Makefile raiz com targets AD-003 (`help`, `trust-ca`, `build`, `up`, `down`, `ps`, `logs`, `shell-*`, `migrate`, `smoke`, `test`, `test-backend`, `test-frontend`, `lint`).  
**Where**: `Makefile`  
**Depends on**: T3, T15, T16  
**Reuses**: design §Makefile, AD-003  
**Requirement**: DOCKER-27

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `make help` lista todos os targets
- [x] Compose sempre via `--env-file docker/versions.env`
- [x] `make up` invoca validate-env + trust-ca quando necessário
- [x] Gate check passes: `make help`

**Tests**: none  
**Gate**: build

---

### T18: Smoke e testes de integração Compose P1

**What**: Suite `tests/smoke/` e `tests/compose/` cobrindo ACs P1: dual HTTPS health, Redis policies, depends_on, migration, filas.  
**Where**: `tests/smoke/`, `tests/compose/`  
**Depends on**: T17  
**Reuses**: spec §Independent Tests P1, `docs/testing.md` §10 smoke  
**Requirement**: DOCKER-01–18 (verificação integrada)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Script smoke: `curl` HTTPS `app.localhost` e `go.localhost` `/health` → 200
- [x] Script valida `docker compose ps` all healthy
- [x] Script valida CONFIG GET Redis (policies distintas)
- [x] Script valida `docker compose config` base válido
- [x] `make test` orquestra profile smoke efêmero
- [x] Gate check passes: `make test`
- [x] Test count: ≥4 smoke assertions pass

**Tests**: integration  
**Gate**: full

---

### T19: Profile `test` — isolamento CI

**What**: Profile `test` com `COMPOSE_PROJECT_NAME` sufixo, volumes `_test`, sem portas de datastore.  
**Where**: `docker-compose.yml`  
**Depends on**: T18  
**Reuses**: design §Profiles, AD-004  
**Requirement**: DOCKER-19

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Volumes de teste separados do dev
- [x] Postgres/Redis sem `ports:` no profile test
- [x] Test script confirma isolamento
- [x] Gate check passes: `docker compose --profile test config`

**Tests**: integration (estendido em `tests/compose/test-profile.sh`)  
**Gate**: full

---

### T20: Profile `docs` — Swagger UI

**What**: Serviço `swagger-ui` profile `docs` + rota Nginx `/docs`.  
**Where**: `docker-compose.yml`, pin `SWAGGER_UI_VERSION`  
**Depends on**: T15  
**Reuses**: design §Swagger UI, `docs/openapi.yaml`  
**Requirement**: DOCKER-20

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Swagger monta `docs/openapi.yaml`
- [x] Acessível via `https://app.localhost/docs` com profile ativo
- [x] Healthcheck swagger-ui
- [x] Gate check passes: smoke docs em `make test` ou target `make up-docs` documentado

**Tests**: integration  
**Gate**: full

---

### T21: Profile `benchmark` — scale workers

**What**: Profile `benchmark` com `analytics-worker` scale 2 e env documentado.  
**Where**: `docker-compose.yml`, `tests/load/README.md` (stub)  
**Depends on**: T14  
**Reuses**: design §Profiles, `docs/testing.md` §8  
**Requirement**: DOCKER-21

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `docker compose --profile benchmark config` mostra 2 analytics workers
- [ ] README stub em `tests/load/` referencia variáveis
- [ ] Gate check passes: `docker compose --profile benchmark config`

**Tests**: none  
**Gate**: build

---

### T22: Profile `observability` — OTel collector stub

**What**: Serviço `otel-collector` mínimo profile `observability`, sem publicar portas no host.  
**Where**: `docker/otel/`, `docker-compose.yml`  
**Depends on**: T15  
**Reuses**: design §OpenTelemetry Collector  
**Requirement**: DOCKER-22

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Collector stub sobe com healthcheck `:13133`
- [ ] Portas OTLP não publicadas no host
- [ ] Gate check passes: `docker compose --profile observability config`

**Tests**: none  
**Gate**: build

---

### T23: `docker-compose.prod.yml` override

**What**: Override produção: restart, limits, sem bind mounts, datastores fechados, workers 2+1.  
**Where**: `docker-compose.prod.yml`  
**Depends on**: T15, T21  
**Reuses**: design §Produção, AD-002, AD-004  
**Requirement**: DOCKER-23, DOCKER-24, DOCKER-26

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `restart: unless-stopped` nos serviços app
- [ ] CPU/RAM limits documentados
- [ ] Sem bind mounts de código
- [ ] Datastores sem `ports:`
- [ ] Workers 2 analytics + 1 notification
- [ ] Gate check passes: `tests/compose/prod-config.sh`

**Tests**: integration  
**Gate**: full

---

### T24: Build multiarch documentado

**What**: Script ou doc `docker/scripts/build-multiarch.sh` para amd64+arm64 via buildx.  
**Where**: `docker/scripts/build-multiarch.sh`, nota em README  
**Depends on**: T4, T5, T6  
**Reuses**: design §P2 Prod images, `docs/architecture.md` §11  
**Requirement**: DOCKER-25

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Script buildx documentado para backend e frontend
- [ ] Sem alteração manual de Dockerfile entre arquiteturas
- [ ] Gate check passes: `bash -n docker/scripts/build-multiarch.sh`

**Tests**: none  
**Gate**: build

---

### T25: README — seção Ambiente Docker

**What**: Atualizar README com bootstrap, URLs, troubleshooting, referência ao Makefile.  
**Where**: `README.md`  
**Depends on**: T17, T18  
**Reuses**: spec P3 Ergonomia, design §Fluxo bootstrap  
**Requirement**: DOCKER-27, DOCKER-28, DOCKER-29

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Seção "Ambiente Docker" em português
- [ ] Pré-requisitos, `make up`, URLs locais, trust-ca, troubleshooting
- [ ] Seeds determinísticos restritos a local/CI documentados
- [ ] Gate check passes: revisão manual + link para Makefile targets

**Tests**: none  
**Gate**: build

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5 ──→ T6 ──→ T7 ──→ T8
Phase 2:  T9 ──→ T10 ──→ T11 ──→ T12
Phase 3:  T13 ──→ T14 ──→ T15 ──→ T16 ──→ T17 ──→ T18
Phase 4:  T19 ──→ T20 ──→ T21 ──→ T22
Phase 5:  T23 ──→ T24 ──→ T25
```

Execution is strictly sequential — there is no intra-phase parallelism.

**Sub-agent packing (Execute):** 25 tasks → ~4 batches (~7 tasks each):

| Batch | Phases | Tasks |
| ----- | ------ | ----- |
| 1 | Phase 1 | T1–T8 |
| 2 | Phase 2 + Phase 3 (partial) | T9–T15 |
| 3 | Phase 3 (cont.) + Phase 4 | T16–T22 |
| 4 | Phase 5 | T23–T25 |

---

## Task Granularity Check

| Task | Scope | Status |
| ---- | ----- | ------ |
| T1: versions.env + skeleton | 1 config file + dirs | ✅ Granular |
| T2: Redis configs | 2 config files, 1 concern | ✅ Granular |
| T3: TLS/validate scripts | 3 scripts, 1 concern | ✅ Granular |
| T4: PHP Dockerfile + FPM | 1 Dockerfile + runtime configs | ✅ Granular |
| T5: Node Dockerfile | 1 Dockerfile | ✅ Granular |
| T6: Nginx Dockerfile + global | 1 Dockerfile + 1 conf | ✅ Granular |
| T7: app.localhost vhost | 1 nginx conf | ✅ Granular |
| T8: go.localhost vhost | 1 nginx conf | ✅ Granular |
| T9: Laravel stub | 1 scaffold | ✅ Granular |
| T10: Pest backend tests | 1 test layer | ✅ Granular |
| T11: Next.js stub | 1 scaffold | ✅ Granular |
| T12: Vitest frontend test | 1 test file | ✅ Granular |
| T13: Compose datastores | 1 compose slice | ✅ Granular |
| T14: Compose backend/workers | 1 compose slice | ✅ Granular |
| T15: Compose frontend/nginx | 1 compose slice | ✅ Granular |
| T16: .env.example | 1 file | ✅ Granular |
| T17: Makefile | 1 file | ✅ Granular |
| T18: Smoke suite P1 | 1 test directory | ✅ Granular |
| T19: Profile test | 1 profile | ✅ Granular |
| T20: Profile docs | 1 profile + service | ✅ Granular |
| T21: Profile benchmark | 1 profile | ✅ Granular |
| T22: Profile observability | 1 profile + stub | ✅ Granular |
| T23: prod override | 1 compose file | ✅ Granular |
| T24: multiarch script | 1 script | ✅ Granular |
| T25: README Docker | 1 doc section | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| ---- | ---------------------- | ------------- | ------ |
| T1 | None | T1 (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T1 | T1 → T3 | ✅ Match |
| T4 | T1 | T1 → T4 | ✅ Match |
| T5 | T1 | T1 → T5 | ✅ Match |
| T6 | T1, T3 | T3 → T6 | ✅ Match |
| T7 | T6 | T6 → T7 | ✅ Match |
| T8 | T6 | T6 → T8 | ✅ Match |
| T9 | T4 | T4 → T9 | ✅ Match |
| T10 | T9 | T9 → T10 | ✅ Match |
| T11 | T5 | T5 → T11 | ✅ Match |
| T12 | T11 | T11 → T12 | ✅ Match |
| T13 | T2 | T2 → T13 | ✅ Match |
| T14 | T4, T9, T13 | T4,T9,T13 → T14 | ✅ Match |
| T15 | T6,T7,T8,T11,T14 | T6–T14 → T15 | ✅ Match |
| T16 | T13,T14,T15 | T15 → T16 | ✅ Match |
| T17 | T3,T15,T16 | T3,T15,T16 → T17 | ✅ Match |
| T18 | T17 | T17 → T18 | ✅ Match |
| T19 | T18 | T18 → T19 | ✅ Match |
| T20 | T15 | T15 → T20 | ✅ Match |
| T21 | T14 | T14 → T21 | ✅ Match |
| T22 | T15 | T15 → T22 | ✅ Match |
| T23 | T15, T21 | T15,T21 → T23 | ✅ Match |
| T24 | T4, T5, T6 | T4,T5,T6 → T24 | ✅ Match |
| T25 | T17, T18 | T17,T18 → T25 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| ---- | --------------------------- | --------------- | --------- | ------ |
| T1–T8, T13–T17, T21–T22, T24–T25 | Config / infra | none | none | ✅ OK |
| T9 | Backend routes (untested until T10) | feature | none → T10 | ✅ OK (T10 follows) |
| T10 | Backend stub routes | feature | feature | ✅ OK |
| T11 | Frontend route (untested until T12) | unit | none → T12 | ✅ OK (T12 follows) |
| T12 | Frontend /health | unit | unit | ✅ OK |
| T18 | Compose smoke | integration | integration | ✅ OK |
| T19 | Profile test isolation | integration | integration | ✅ OK |
| T20 | Swagger via compose | integration | integration | ✅ OK |
| T23 | Prod compose config | integration | integration | ✅ OK |

---

## Requirement Traceability (Tasks)

| Requirement ID | Task(s) |
| --- | --- |
| DOCKER-01 | T14, T15, T18 |
| DOCKER-02 | T7, T11, T12, T15, T18 |
| DOCKER-03 | T8, T9, T10, T15, T18 |
| DOCKER-04 | T7, T15, T18 |
| DOCKER-05 | T8, T9, T10, T18 |
| DOCKER-06 | T3, T6, T11, T18 |
| DOCKER-07 | T1, T3, T16, T17 |
| DOCKER-08 | T2, T13, T18 |
| DOCKER-09 | T13, T18 |
| DOCKER-10 | T2, T13, T18 |
| DOCKER-11 | T2, T13, T18 |
| DOCKER-12 | T9, T14, T18 |
| DOCKER-13 | T17, T18 |
| DOCKER-14 | T14, T18 |
| DOCKER-15 | T4, T13, T14, T15 |
| DOCKER-16 | T14, T15 |
| DOCKER-17 | T14, T15 |
| DOCKER-18 | T15, T18 |
| DOCKER-19 | T19 |
| DOCKER-20 | T20 |
| DOCKER-21 | T21 |
| DOCKER-22 | T22 |
| DOCKER-23 | T23, T24 |
| DOCKER-24 | T23 |
| DOCKER-25 | T24 |
| DOCKER-26 | T23 |
| DOCKER-27 | T17, T25 |
| DOCKER-28 | T25 |
| DOCKER-29 | T25 |

**Coverage:** 29 total, 29 mapped to tasks, 0 unmapped ✅
