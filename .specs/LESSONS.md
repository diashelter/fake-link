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

### L-016 — Finalize feature spec.md with precise WHEN/THEN outcomes before Execute so verification does not have to reconstruct ACs from product docs
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `specs` · harmful: 0
- features: auth/bearer-tokens
- evidence: spec.md:DRAFT (no WHEN/THEN ACs) (specs)
- last seen: 2026-07-26T19:11:45Z

### L-017 — Register module Feature test directories in phpunit.xml so default CI and make test-backend discover HTTP probe tests
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `backend/tests` · harmful: 0
- features: auth/bearer-tokens
- evidence: AUTH-18/AUTH-19 — backend/phpunit.xml Feature suite omits modules/Auth/Tests/Feature (backend/tests)
- last seen: 2026-07-26T19:11:45Z

### L-018 — Register every new module test directory in phpunit.xml suites so CI and make test-backend discover Feature tests
- signal: `gate_fail` · recurrence: 1 feature(s) · scope: `backend/phpunit.xml` · harmful: 0
- features: auth/bearer-tokens
- evidence: validation.md:Gate / phpunit.xml Feature suite (backend/phpunit.xml)
- last seen: 2026-07-26T19:12:00Z

### L-019 — Complete WHEN/THEN acceptance criteria in spec.md before marking a feature slice verified
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `specs` · harmful: 0
- features: auth/bearer-tokens
- evidence: validation.md:spec.md draft without WHEN/THEN (specs)
- last seen: 2026-07-26T19:12:00Z

### L-020 — Cover every Form Request validation branch named in the AC matrix with an HTTP 422 assertion and zero side effects, not only a subset of fields
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth,routes,validation` · harmful: 0
- features: auth/registration
- evidence: REG-07 validation ACs 2/4/5/6 — validation.md (auth,routes,validation)
- last seen: 2026-07-26T20:23:10Z

### L-021 — For allowlist privacy ACs, assert via Log::fake or channel spy that consulted emails never appear in log, trace, or metric records
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth,allowlist,telemetry` · harmful: 0
- features: auth/registration
- evidence: Allowlist AC3 — JsonFileInviteAllowlistTest.php:71-89 (auth,allowlist,telemetry)
- last seen: 2026-07-26T20:23:10Z

### L-022 — When an edge case names an HTTP status outcome, assert that status in a Feature or HTTP test, not only a unit-level boolean or domain violation
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth,routes,testing` · harmful: 0
- features: auth/registration
- evidence: Edge cases +alias/unicode/malformed — validation.md (auth,routes,testing)
- last seen: 2026-07-26T20:23:10Z

### L-023 — When a spec edge lists alternate HTTP triggers with OR (missing Content-Type or malformed JSON), assert each trigger path reaches the specified status
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth,routes,http` · harmful: 0
- features: auth/registration
- evidence: Edge: missing Content-Type → 400 — RejectMalformedJson.php:24-26 | validation.md (auth,routes,http)
- last seen: 2026-07-26T20:36:46Z

### L-024 — Assert domain exception getMessage equals the OpenAPI error string, not only errorCode or sentinel absence
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth,exceptions` · harmful: 0
- features: auth/email-verification
- evidence: M5 InvalidVerificationTokenException.php:24 (auth,exceptions)
- last seen: 2026-07-27T17:58:29Z

### L-025 — For every authenticated route in scope, assert 401 UNAUTHENTICATED, TOKEN_RESTRICTED, and ACCOUNT_* on that exact path, not only on a shared probe route
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth,routes` · harmful: 0
- features: auth/email-verification
- evidence: AUTH-23 resend AC4-AC5 / EV-04 (auth,routes)
- last seen: 2026-07-27T17:58:29Z

### L-026 — When an AC requires infrastructure behavior outside the app test suite, mark it explicitly as ops-verified or add a contract testable seam in-repo
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `security,observability` · harmful: 0
- features: auth/email-verification
- evidence: AUTH-25 AC2 access-log redaction (security,observability)
- last seen: 2026-07-27T17:58:29Z

### L-027 — When the spec requires queue retry or permanent-failure side effects, assert tries/backoff or that failure leaves domain state unchanged
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth,jobs` · harmful: 0
- features: auth/email-verification
- evidence: AUTH-20 AC4 job retry (auth,jobs)
- last seen: 2026-07-27T17:58:29Z

### L-028 — When a guard is duplicated by a later atomic consume/update, add a test that isolates the early guard or drop the redundant check so mutation of either path fails tests
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth/reset` · harmful: 0
- features: auth/password
- evidence: M5 ResetPassword.php isUsed early guard (auth/reset)
- last seen: 2026-07-28T21:19:02Z

### L-029 — For password reset and change routes, assert weak or mismatched password returns 422 with no token consume and no bearer revoke
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth/password` · harmful: 0
- features: auth/password
- evidence: Reset AC7 / PW-05 policy confirmation without consume (auth/password) (+1 more)
- last seen: 2026-07-28T21:19:03Z

### L-030 — When a rate-limit AC says count all attempts regardless of HTTP status, assert that validation failures increment the counter before proving 429
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth/rate-limit` · harmful: 0
- features: auth/password
- evidence: Reset-request AC8 / PW-01 PW-04 any-status rate limit (auth/rate-limit)
- last seen: 2026-07-28T21:19:03Z

### L-031 — When asserting no-op updates do not bump updated_at, freeze and advance time around the call so same-second writes cannot mask a skipped no-op guard
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth` · harmful: 0
- features: auth/session-and-profile
- evidence: validation.md Mutation 2 / UpdateCurrentUser.php:29 no-op === flip (auth)
- last seen: 2026-07-30T13:04:31Z

### L-032 — When the same rate-limit middleware is shared across routes, assert 429 on each route named by the acceptance criteria, not only on one sibling endpoint
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth` · harmful: 0
- features: auth/session-and-profile
- evidence: spec logout-all AC7 / PATCH AC7 write throttle 429 (auth)
- last seen: 2026-07-30T13:04:31Z

### L-033 — For compound validation ACs joined by OR, cover every branch (missing, empty-after-normalize, maxLength, extras) with its own assertion
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth` · harmful: 0
- features: auth/session-and-profile
- evidence: spec PATCH AC5 absent/>120; logout-all AC6 maxLength (auth)
- last seen: 2026-07-30T13:04:31Z

### L-034 — When headers are required on success and error responses, assert Cache-Control and request id on at least one representative error path
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth` · harmful: 0
- features: auth/session-and-profile
- evidence: spec SP-13 error-path headers (auth)
- last seen: 2026-07-30T13:04:31Z

### L-035 — When OpenAPI alignment is an AC, define an automated check or explicit smoke checklist; otherwise mark it as a process gate not a testable outcome
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth` · harmful: 0
- features: auth/session-and-profile
- evidence: spec SP-13 OpenAPI alignment AC (auth)
- last seen: 2026-07-30T13:04:31Z

### L-036 — Assert viewport reflow outcomes (e.g. no horizontal overflow at the documented min width) instead of assuming Tailwind responsive classes are enough
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend` · harmful: 0
- features: bff-auth/foundation
- evidence: FND-07 / validation.md Fix 1 (frontend)
- last seen: 2026-07-31T01:23:32Z

### L-037 — When Independent Test requires staged-file hook simulation, ship a behavioral script that proves auto-fix and non-zero exit — config presence alone is not evidence
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/hooks` · harmful: 0
- features: bff-auth/foundation
- evidence: FND-13,FND-14 / validation.md Fix 2 (frontend/hooks)
- last seen: 2026-07-31T01:23:32Z

### L-038 — Absence requirements (forbidden routes or packages) need automated allowlist/denylist assertions, not checklist-only verification
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend` · harmful: 0
- features: bff-auth/foundation
- evidence: FND-02,FND-08 / validation.md Fix 3 (frontend)
- last seen: 2026-07-31T01:23:32Z

### L-039 — When the spec requires utilities to apply, assert concrete class tokens or rendered style outcomes rather than only theme CSS markers
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend` · harmful: 0
- features: bff-auth/foundation
- evidence: BFFUI-05/FND-07 Tailwind apply / validation.md (frontend)
- last seen: 2026-07-31T01:23:32Z

### L-040 — Lint-staged path-skip behavior needs a staged non-matching-file proof, not only glob configuration review
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend/hooks` · harmful: 0
- features: bff-auth/foundation
- evidence: FND-14 non-frontend skip / validation.md (frontend/hooks)
- last seen: 2026-07-31T01:23:32Z

### L-041 — When a hook AC requires exit non-zero on unfixable lint errors, assert that exit code with a staged bad file — requiring eslint --fix in config alone is incomplete
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend/hooks` · harmful: 0
- features: bff-auth/foundation
- evidence: FND-14 hard-fail / validation.md re-verify iter 1 (frontend/hooks)
- last seen: 2026-07-31T01:30:26Z

### L-042 — When rejecting whitespace-only input, assert against a value that survives TrimStrings (or disable trimming in the test) so whitespace validation rules are not masked by framework middleware
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth,validation,http-requests` · harmful: 0
- features: auth/module-closure
- evidence: M3 VerifyEmailRequest.php:40 / EmailVerificationTest.php:155 (auth,validation,http-requests)
- last seen: 2026-08-11T13:34:56Z

### L-043 — When a spec lists a concurrency edge for session rotation, add an explicit concurrent-call test asserting the old id is invalid and at most one successor remains valid
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth/session` · harmful: 0
- features: bff-auth/session-core
- evidence: validation.md edge: concurrent rotateSession — no file:line (auth/session)
- last seen: 2026-08-11T16:35:24Z

## Quarantined (failed when applied — ignore)

A confirmed lesson that recurred alongside failure. Kept for the maintainer to review.

_none_
