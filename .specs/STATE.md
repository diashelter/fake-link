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

## Handoff

- **Feature**: `links/slug-policy` — Specify ✅ · Discuss ✅ · Design ✅ (Draft) · Tasks ✅ (reconciliado + aprovado 2026-09-01) · Execute ✅ (T1–T13 commitados) · Validate ⏳ (Verifier pendente)
- **Completed**: `links/foundation` em `main` (`f82f57c`…`922bbbb`). `links/slug-policy` Batch A (T1–T8, `f016f51`…`c57a849`) + Batch B (T9 `93d1139`, T10 `22b9820`, T11 `ed8dcf0`, T12 `68ac6eb`, T13 pendente commit) em `feat/links-slug-policy`.
- **Gates executados**: T9 full-suite 630/630 ✅ · T10 full-suite 644/644 ✅ · T11 `make lint-backend` + `make test-architecture` 17/17 + coverage 648/648 (Links **92.30% linhas / 91.00% métodos**) ✅ · T12: 4/4 testes próprios ✅, `pint` ✅, `phpstan modules/Links` ✅ — **full-suite gate não fechou**: única falha recorrente é `QualityToolingTest` (phpstan como subprocesso, timeout de 180s) sob carga da máquina (load avg chegou a 41 por processo alheio), não é defeito de código.
- **Next step**: (1) commitar T13 (docs); (2) re-rodar `make lint && make test-backend-coverage` numa janela sem carga para fechar o gate de T12/T13; (3) despachar Verifier (spec-anchored check + sensor de discriminação → `validation.md`).
- **Blockers**: máquina sobrecarregada por projeto alheio (também segura a porta 6380 do Redis, forçando o workaround `docker compose -f docker-compose.yml run … php artisan test` no lugar de `make test-backend`).
- **Branch**: `feat/links-slug-policy` (de `main`)
- **Prior feature**: `links/foundation` — Verified PASS 2026-08-30
- **Gap de baixo risco herdado**: rollback sequence (`migrate:rollback` ordem inversa) sem teste dedicado; RESTRICT constraints garantem corretude

### Fase 1: Auth + BFF — CONCLUÍDA ✅

Todas as 9 fatias do pacote BFF Auth entregues e verificadas. Critérios de saída da Fase 1 atendidos:

- Usuário convidado cadastra, verifica e-mail, autentica e encerra sessão(ões) pelo BFF ✅
- Nenhum teste de browser, HTML, storage, log ou trace encontrou token Bearer ✅
- CSRF, cookie, Origin, HMAC, returnUrl, flush Redis e expirações passam nas suítes ✅
- Gate E2E Playwright (`make test-e2e-auth`) entregue e passando em CI ✅

### Fase 2: Links + Redirect — INICIADA

Estrutura de specs da API backend criada em `.specs/features/links/` (índice + 13 fatias seed, catálogo `LNK-01`…`LNK-124`). Fatia 1 (`foundation`) fechada em Specify/Design/Tasks (25 requisitos, 15 tasks); fatia 2 (`slug-policy`) fechada em Specify/Discuss (SLG-01…18); fatia 3 (`destination-policy`) fechada em Specify/Discuss/Design/Tasks (LDST-01…24, 12 tasks). Fatias 4–13 seguem em status **Seed**. Nenhuma linha de código dos módulos existe ainda. O pacote frontend correspondente (`bff-links/`) será aberto depois.
