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

## Handoff

**Feature:** `docker-foundation` — **PASS** (re-verify 2026-07-22)

**Branch:** `main`  
**HEAD:** `5155c84`

### Entregue

- T1–T25 + gaps do Verifier (cookies, nginx smoke, redis hosts, graceful stop, unhealthy, profiles, prod, multiarch, README)
- Gate: `make test` green (Pest 5, Vitest 4, compose/smoke) — reconfirmado 2026-07-22
- Sensor: stop_grace_period killed; prior 4/4 at HEAD
- Relatório: `.specs/features/docker-foundation/validation.md`

### Residual conhecido

- DOCKER-25: multiarch documentado via `build-multiarch.sh` (`bash -n`); Full gate não executa buildx (spec-precision)

### Próximo passo

Iniciar próxima feature do roadmap (domínio Auth/Links) ou endurecer DOCKER-25 com gate buildx opcional.
