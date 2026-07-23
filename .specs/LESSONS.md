# LESSONS — auto-maintained by scripts/lessons.py

> Machine-owned. Do NOT hand-edit. Changes are overwritten on the next `lessons.py` write.
> Canonical state lives in `.specs/lessons.json`. Edit lessons only via the script.
> promote_threshold=2 distinct features · window_days=45 · quarantine_threshold=2

## Confirmed (load these at Specify/Design)

Corroborated across multiple features. Safe to apply as guidance.

_none_

## Candidates (under observation — do NOT load as guidance yet)

Seen once or not yet corroborated. Tracked, not trusted.

### L-001 — Assert distinct Redis host env vars (or live pings) for cache vs queue from the app container, not only Compose YAML
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `compose,redis` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-12 (compose,redis)
- last seen: 2026-07-22T01:13:29Z

### L-002 — When stop_grace_period or AOF durability is specified, add a SIGTERM or restart assertion that proves the queue store survives
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `compose,redis` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-17 (compose,redis)
- last seen: 2026-07-22T01:13:30Z

### L-003 — When healthchecks are a requirement, fault-inject an upstream failure and assert docker compose ps reports unhealthy
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `compose,health` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-18 (compose,health)
- last seen: 2026-07-22T01:13:30Z

### L-004 — Compose profile Done-when checks must be scripts invoked by make test, not only manual docker compose config
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `compose,profiles` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-21 (compose,profiles)
- last seen: 2026-07-22T01:13:30Z

### L-005 — Assert Compose stop_grace_period from rendered config (or stop without -t); do not hardcode docker stop -t in the grace-period test
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `compose` · harmful: 0
- features: docker-foundation
- evidence: tests/compose/graceful-stop.sh:10 / docker-compose.yml:34 (compose)
- last seen: 2026-07-22T01:31:40Z

### L-006 — Run validate-env against .env.example inside the Full gate when the AC requires complete bootstrap env vars
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `env` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-07 (env)
- last seen: 2026-07-22T01:31:40Z

### L-007 — Parse compose config JSON for depends_on service_healthy conditions instead of relying on static file review alone
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `compose` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-16 (compose)
- last seen: 2026-07-22T01:31:40Z

### L-008 — Either execute a multiarch buildx dry-run in CI/gate or explicitly document script-only evidence as accepted for the multiarch AC
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `docker` · harmful: 0
- features: docker-foundation
- evidence: DOCKER-25 (docker)
- last seen: 2026-07-22T01:34:58Z

### L-009 — When Laravel/Symfony majors break stable PHPMD, pin phpmd 3.x-dev with a SPEC_DEVIATION and adapt the CLI to analyze/format/ruleset flags rather than inventing a substitute smell tool
- signal: `spec_deviation` · recurrence: 1 feature(s) · scope: `backend-quality` · harmful: 0
- features: backend-quality-tooling
- evidence: backend/phpmd.xml:3-7 (backend-quality)
- last seen: 2026-07-22T22:39:47Z

### L-010 — For PHPStan 2.x, drop obsolete neon params removed upstream and scope Pest Feature TestCall ignoreErrors to tests/* instead of disabling strict analysis globally
- signal: `spec_deviation` · recurrence: 1 feature(s) · scope: `backend-quality` · harmful: 0
- features: backend-quality-tooling
- evidence: backend/phpstan.neon:14-20 (backend-quality)
- last seen: 2026-07-22T22:39:47Z

### L-011 — Add an automated smoke that asserts fake_link_testing exists when Postgres init runs; infra-only scripts are not verified by green unit tests alone
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `docker/postgres` · harmful: 0
- features: auth/foundation
- evidence: FND-10 AC1 | validation.md (docker/postgres)
- last seen: 2026-07-23T12:31:01Z

### L-012 — Assert migration schema contracts in integration tests via information_schema or pg_catalog, not only by reading migration source files
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `migrations` · harmful: 0
- features: auth/foundation
- evidence: FND-05 | migration AC2 | validation.md (migrations)
- last seen: 2026-07-23T12:31:01Z

### L-013 — Boot tests must assert ServiceProvider registration by resolving a module port from the container, not only that the application starts
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `service-providers` · harmful: 0
- features: auth/foundation
- evidence: FND-01 AC1 | validation.md (service-providers)
- last seen: 2026-07-23T12:31:01Z

### L-014 — Hashing tests must assert published config defaults from config files, not only override config in test beforeEach
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth/hashing` · harmful: 0
- features: auth/foundation
- evidence: AUTH-08 AC6 | validation.md (auth/hashing)
- last seen: 2026-07-23T12:31:02Z

### L-015 — Integration tests must cover NOT NULL and CHECK constraint failures for required persistence fields listed as spec edge cases
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth/integration` · harmful: 0
- features: auth/foundation
- evidence: edge-case terms/status | validation.md (auth/integration)
- last seen: 2026-07-23T12:31:02Z

## Quarantined (failed when applied — ignore)

A confirmed lesson that recurred alongside failure. Kept for the maintainer to review.

_none_
