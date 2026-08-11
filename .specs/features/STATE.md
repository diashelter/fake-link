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
| AD-008 | 2026-07-21 | Compose: `docker-compose.yml` + profiles + `docker-compose.prod.yml` override |
| AD-009 | 2026-07-22 | Stack de qualidade backend: Pint + Larastan nível 6 + phpstan-strict-rules + PHPMD + Pest Arch + PCOV; gates locais/CI via Docker e Makefile (`make lint`, `make test-backend-coverage`, workflow `.github/workflows/backend-quality.yml`); sem PHPCS/PHP-CS-Fixer/PHP Insights; `phpmd/phpmd` em `3.x-dev` por compatibilidade Symfony 8 / Laravel 13 |
| AD-010 | 2026-07-23 | Identificador canônico de contas Auth (`users.id`): **UUID v7** (PostgreSQL `uuid`), gerado na aplicação |
| AD-011 | 2026-07-23 | Testes backend com I/O de banco usam exclusivamente PostgreSQL **`fake_link_testing`**; proibido `fake_link` (dev) e bancos de produção |
| AD-012 | 2026-07-23 | **Todas** as entidades de domínio e FKs relacionadas usam **UUID v7** (PostgreSQL `uuid`, RFC 9562), gerados na aplicação; ULID não é utilizado |
| AD-013 | 2026-07-30 | Pacote BFF+UI Auth especificado em 9 fatias-semente sob `.specs/features/bff-auth/`; IDs estáveis `BFFUI-XX`; cada fatia exige Specify deepen antes de Design/Execute |

## Handoff

- **Feature**: `bff-auth/session-core` — Specify ✅ · Design ✅ · Tasks ✅ · Execute ✅ · Validate ✅ PASS
- **Phase / Task**: Re-validation iteration 1 complete — Ready
- **Completed**: T1–T16; Fix 1 (`899d855` claim DEL before create + concurrent rotate test); SC-01…SC-18 + concurrent edge Verified; sensor 3/3 killed; gate 91/0
- **In-progress**: none
- **Next step**: Feature ready (merge/handoff); no further fix→re-verify iterations
- **Blockers**: none
- **Artifacts**: `.specs/features/bff-auth/session-core/{spec,design,tasks,validation}.md`
- **Branch**: `feature/bff-auth-session-core`
- **Gate**: `make lint-frontend && make test-frontend` — 91 passed, 0 failed
- **Validation**: PASS — see `.specs/features/bff-auth/session-core/validation.md` (diff `fa24b26^..899d855`)
