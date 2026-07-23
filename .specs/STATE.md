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

## Handoff

**Feature:** `backend-quality-tooling` — **COMPLETE** (2026-07-22)

**Branch:** `feature/package-defaults`  
**HEAD (implementation):** `72dccea`  
**Validation:** PASS — `.specs/features/backend-quality-tooling/validation.md`

### Progresso

- Worker 1 (T1–T9): completo — `9cb7f68`…`76bec2e`
- Worker 2 (T10–T15): completo — `3a56a10`…`72dccea`
- Verifier: **PASS** — 34/34 ACs, gate green, 3/3 mutants killed; lessons L-009/L-010 (SPEC_DEVIATION)

### Deviations documentados

- PHPMD `3.x-dev` (Symfony 8 / Laravel 13)
- PHPStan 2 neon params; Pest `TestCall` ignore

### Uncommitted (pós-Execute)

- `.specs/features/backend-quality-tooling/{spec,design,tasks,validation}.md` (artefatos de spec + relatório)
- `.specs/LESSONS.md`, `.specs/lessons.json`, `.specs/STATE.md` (handoff + lessons)
- `backend/storage/coverage/` — não versionar

### Próximo passo

Commit dos artefatos `.specs/` se desejado; abrir PR da branch `feature/package-defaults` quando pronto.