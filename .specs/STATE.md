# Estado do projeto — Fake Link

## Decisions log

| ID | Data | Decisão |
| --- | --- | --- |
| AD-001 | 2026-07-21 | TLS local via script OpenSSL versionado (`docker/nginx/certs/`); CA importada manualmente pelo dev |
| AD-002 | 2026-07-21 | Profile dev: 1 analytics-worker + 1 notification-worker; benchmark/prod: 2+1 |
| AD-003 | 2026-07-21 | Interface operacional única: Makefile na raiz |
| AD-004 | 2026-07-21 | Dev publica portas de PostgreSQL/Redis no host; test e prod não publicam |
| AD-005 | 2026-07-21 | Pin de stack em `docker/versions.env` (PHP 8.4.23, Laravel 13.16.1, Node 24.18.0, Next 16.2.11, PG 18.4, Redis 8.8.0, Nginx 1.30.4, Composer 2.10.2, pnpm 11.15.1) |
| AD-006 | 2026-07-21 | Nginx único como ingress TLS; roteamento por `server_name` (`app.localhost` vs `go.localhost`) |
| AD-007 | 2026-07-21 | PHP-FPM no backend; Nginx faz proxy/FastCGI — apps não terminam TLS |
| AD-008 | 2026-07-21 | Compose: `docker-compose.yml` + profiles + `docker-compose.dev.yml` override |
| AD-009 | 2026-07-22 | Stack de qualidade backend: Pint + Larastan nível 6 + phpstan-strict-rules + PHPMD + Pest Arch + PCOV; gates locais/CI via Docker e Makefile (`make lint`, `make test-backend-coverage`, workflow `.github/workflows/backend-quality.yml`); sem PHPCS/PHP-CS-Fixer/PHP Insights; `phpmd/phpmd` em `3.x-dev` por compatibilidade Symfony 8 / Laravel 13 |
| AD-010 | 2026-07-23 | Identificador canônico de contas Auth (`users.id`): **UUID v7** (PostgreSQL `uuid`), gerado na aplicação |
| AD-011 | 2026-07-23 | Testes backend com I/O de banco usam exclusivamente PostgreSQL **`fake_link_testing`**; proibido `fake_link` (dev) e bancos de produção |
| AD-012 | 2026-07-23 | **Todas** as entidades de domínio e FKs relacionadas usam **UUID v7** (PostgreSQL `uuid`, RFC 9562), gerados na aplicação; ULID não é utilizado |
| AD-013 | 2026-07-30 | Frontend modular: domínio em `frontend/modules/{module}/`; App Router em `frontend/app/` (sem `src/`) |
| AD-014 | 2026-07-30 | Qualidade frontend: ESLint 9 flat + Prettier + TypeScript strict via `make lint-frontend`; `make lint` inclui frontend após backend; Husky + lint-staged na **raiz** do monorepo (globs `frontend/**` only) |
| AD-015 | 2026-07-30 | Estilo frontend greenfield: **Tailwind CSS v4** (CSS-first, `@tailwindcss/postcss`); tema claro único; Radix adiado além da fundação BFF Auth |
| AD-016 | 2026-08-11 | OpenAPI: lint via **Spectral** (`@stoplight/spectral-cli`) no monorepo (`make lint-openapi`); contract tests Pest em `modules/{Module}/Tests/Contract/`; containers backend montam `./docs:/var/www/docs:ro` (`OPENAPI_SPEC_PATH`) |
| AD-017 | 2026-08-11 | Route Handlers BFF Auth usam prefixo **`/api/bff/...`** no App Router Next.js; Laravel permanece em `/api/v1/...` via nginx |
| AD-018 | 2026-08-29 | Profile `e2e` + `docker-compose.e2e.yml` + `make test-e2e-auth` + `frontend-e2e.yml`; Playwright roda no container `frontend` (stage `e2e`); Mailpit para captura de e-mail; TTLs de sessão BFF configuráveis por env com defaults inalterados |
| AD-019 | 2026-08-30 | Parsing de URL não confiável usa **`league/uri`** (promovido a dependência direta em `backend/composer.json`, `^7.8`); `parse_url()` e regex sobre a autoridade são proibidos para entrada de usuário — vale para `Links`, `Redirects` e qualquer fatia futura que leia URL. Checagens que o parser reescreveria em silêncio (bytes não-ASCII, caracteres de controle, percent-encoding malformado) rodam **antes** dele |
| AD-020 | 2026-09-01 | Código de erro estável **`SLUG_GENERATION_FAILED`** (`503`, com `Retry-After`) para exaustão das tentativas de gerar um slug automático livre (esgotou o teto de 5 colisões ou o teto de 5 descartes por denylist). Registrado em `docs/api.md` §7. O domínio (`Modules\Links`) expõe a falha tipada `SlugGenerationExhausted`; o mapeamento HTTP e a entrada na OpenAPI pertencem à fatia `link-creation` que expõe o endpoint (escopo diferido). Nenhuma decisão anterior é superseded |
| AD-021 | 2026-09-17 | `ApiFormRequest` aceita `errorCodes(): array` opcional (`campo.regra` → código estável); ausência do mapa preserva `'INVALID'`. Convenção válida para todos os módulos (Auth permanece no default). Nenhuma decisão anterior é superseded |

## Handoff

- **Feature atual**: `links/link-queries` — Specify ✅ · Design ✅ · Tasks ✅ · Execute pendente (T1–T8)
- **Próximo passo**: Execute das tasks em `.specs/features/links/link-queries/tasks.md`, a partir de T1.
- **Artefatos trazidos de**: `feature/link-queries` (`spec.md`, `design.md`, `tasks.md`, migration de índices, `LinkQueryIndexesTest.php`) para `feature/query` (base em `main` pós-PR #28).
- **Feature anterior**: `links/idempotency` — Specify ✅ · Design ✅ · Tasks ✅ · Execute ✅ (T1–T9) · Validate ✅ **PASS** (re-verify after Fix iteration 1/3)
- **Verifier (idempotency)**: 2026-09-18 — report `.specs/features/links/idempotency/validation.md`. Diff `bc6b4755..e1d90171`. Spec-anchored: **20/20 ACs**; sensor 6/6 killed; gate `make test-backend` **1058 passed** + `lint-openapi` + `lint-backend` OK.
- **Completed (prévia)**: T1–T17 on `feature/link-creation` (`46e081a9`…`7dfdec0d`). Verifier report: `.specs/features/links/link-creation/validation.md` (2026-09-18).
- **In-progress**: none — artefatos de Specify/Design/Tasks presentes; Execute ainda não iniciado nesta branch.
- **Blockers**: none. Known inherited: `make lint` pode falhar em `lint-frontend` (e2e Playwright `launchOptions` TS2353) — pré-existente em `main`.
- **Branch**: `feature/query` (de `main`)
- **Prior feature**: `links/destination-policy` — Verified PASS 2026-09-17, mesclada via PR #26
- **AD-021**: `errorCodes()` opcional em `ApiFormRequest` (default `'INVALID'`)

### Fase 1: Auth + BFF — CONCLUÍDA ✅

Todas as 9 fatias do pacote BFF Auth entregues e verificadas. Critérios de saída da Fase 1 atendidos:

- Usuário convidado cadastra, verifica e-mail, autentica e encerra sessão(ões) pelo BFF ✅
- Nenhum teste de browser, HTML, storage, log ou trace encontrou token Bearer ✅
- CSRF, cookie, Origin, HMAC, returnUrl, flush Redis e expirações passam nas suítes ✅
- Gate E2E Playwright (`make test-e2e-auth`) entregue e passando em CI ✅

### Fase 2: Links + Redirect — INICIADA

Estrutura de specs em `.specs/features/links/` (índice + 13 fatias, catálogo `LNK-01`…`LNK-124`). Fatias 1–5 Verified PASS: `foundation`, `slug-policy` (PR #25), `destination-policy` (PR #26), `link-creation` (PR #27), `idempotency` (PR #28). Fatia 6 (`link-queries`) tem spec/design/tasks nesta branch; fatias 7–13 seguem em status **Seed**. Superfície HTTP de Links: somente `POST /api/v1/links` até o Execute desta fatia. O pacote frontend correspondente (`bff-links/`) será aberto depois. Docs de contrato vs runtime: `docs/api.md` §1.1.
