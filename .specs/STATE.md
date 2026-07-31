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

## Handoff

- **Feature**: `bff-auth/foundation` — Specify ✅ · Design ✅ · Tasks ✅ · Execute ✅ · Validate ✅
- **Phase / Task**: Complete (T1–T16 + Verifier PASS)
- **Completed**: All foundation tasks; validation PASS (29/30 ACs; 1 FND-14 hard-fail precision gap)
- **In-progress**: none
- **Next step**: Próxima fatia BFF Auth (`session-core` ou conforme índice `.specs/features/bff-auth/README.md`)
- **Blockers**: none
- **Artifacts**: `.specs/features/bff-auth/foundation/{spec,context,design,tasks,validation}.md`
- **Branch**: `feature/auth-fundation-frontend`
- **Gate**: `make lint-frontend` + `make test-frontend` (30) + `make test-frontend-coverage` (≥75%) verdes
- **Tip commits**: `e9db79f`…`aeac7ed`
