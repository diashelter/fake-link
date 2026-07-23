# Auth — Fundação Validation

**Date**: 2026-07-23
**Spec**: `.specs/features/auth/foundation/spec.md`
**Diff range**: `498540a^..b1762cb` (first foundation commit `498540a` through HEAD)
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1   | ✅ Done | Postgres init script + compose mount |
| T2   | ✅ Done | `.env.testing` + `phpunit.xml` PG testing |
| T3   | ✅ Done | Makefile targets use `fake_link_testing` |
| T4   | ✅ Done | `docs/testing.md` §2 updated |
| T5   | ✅ Done | UUID v7 migration, legacy tables removed |
| T6   | ✅ Done | Repository/service contracts |
| T7   | ✅ Done | VOs, enums, exceptions + unit tests |
| T8   | ✅ Done | `PasswordPolicy` + unit tests |
| T9   | ✅ Done | `User` entity + unit tests |
| T10  | ✅ Done | `config/hashing.php` Argon2id |
| T11  | ✅ Done | `LaravelPasswordHasher` + unit tests |
| T12  | ✅ Done | Model, mapper, UUID v7 generator |
| T13  | ✅ Done | `EloquentUserRepository` + integration tests |
| T14  | ✅ Done | Skeleton `App\Models\User` removed |
| T15  | ✅ Done | `AuthServiceProvider` registered |
| T16  | ✅ Done | PHPStan + PCOV paths include Auth |
| T17  | ✅ Done | AD-010/AD-011 in STATE.md |
| T18  | ✅ Done | `docs/data-model.md` UUID v7 sync |

> **Note:** `tasks.md` header still reads "Draft — aguardando aprovação"; all 18 tasks are implemented in git history.

---

## Spec-Anchored Acceptance Criteria

### P1: Scaffold e registro do módulo (FND-01, FND-02, FND-03)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN app boota THEN `Modules\Auth` autoloada e `AuthServiceProvider` registrado em `bootstrap/providers.php` | Provider resolvable; namespace autoloaded | — | ❌ GAP — boot succeeds indirectly but no assertion on provider registration |
| WHEN Pest Arch roda THEN regras modulares aplicam a `Modules\Auth\*` | Auth arch rules enforced | `backend/tests/Architecture/ModularMonolithTest.php:41` — `arch("Auth controllers do not use Eloquent models directly")` | ✅ PASS (gate) |
| WHEN `phpstan analyse` roda THEN inclui `modules/Auth/` com exit 0 | PHPStan exit 0 with Auth in paths | `backend/tests/Feature/QualityToolingTest.php:28` — `expect($result->exitCode())->toBe(0, $message)` | ⚠️ Spec-precision gap — asserts global exit 0, not Auth path inclusion |
| WHEN sem rotas Auth THEN health check passa | HTTP 200 `{status: ok}` | `backend/tests/Feature/HealthTest.php:6` — `$response->assertOk()->assertJson(['status' => 'ok'])` | ✅ PASS |

### P1: Banco PostgreSQL dedicado (FND-10, FND-11)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN Postgres sobe THEN `fake_link_testing` existe e é distinto de `fake_link` | DB created on init | — | ❌ GAP — init script exists (`docker/postgres/init/01-create-testing-database.sql`) but no automated test |
| WHEN `make test-backend` roda THEN conecta a `fake_link_testing` via PG | `DB_DATABASE=fake_link_testing`, not SQLite/dev | `backend/modules/Auth/Tests/Integration/DatabaseSafetyTest.php:13` — `expect(config('database.connections.pgsql.database'))->toBe('fake_link_testing')` | ✅ PASS |
| WHEN `APP_ENV=testing` THEN `DB_DATABASE=fake_link_testing` | Env fixed in testing | `backend/modules/Auth/Tests/Integration/DatabaseSafetyTest.php:13` — same assertion | ✅ PASS |
| WHEN teste integração executa migrations THEN somente em `fake_link_testing` | Guard aborts wrong DB | `backend/modules/Auth/Tests/Integration/DatabaseSafetyTest.php:17` — `expect(fn () => DatabaseSafetyGuard::assertIsolated('fake_link'))->toThrow(..., 'Integration tests must use fake_link_testing, got "fake_link".')` | ⚠️ Spec-precision gap — guard tested; no assertion that migrations never touch dev DB |
| WHEN `docs/testing.md` §2 inspecionado THEN documenta banco dedicado | Doc describes isolation | — | ❌ GAP — doc verified manually; no test artifact (doc-only AC) |

### P1: Migration e persistência (FND-04, FND-05, FND-06)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN `migrate` roda THEN `users` com PK UUID, CHECK status, termos NOT NULL, UNIQUE email | Full canonical schema | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php:55` — UUID v7 regex; `:99-101` — status/terms persisted; `:72-73` — duplicate email throws | ⚠️ Spec-precision gap — behavior covered; no explicit schema/CHECK assertion |
| WHEN migration inspecionada THEN sem `remember_token`, `password_reset_tokens`, `sessions` | Legacy artifacts absent | — | ❌ GAP — verified in migration source only; no test |
| WHEN `User` salvo com `  Foo@Bar.com  ` THEN persistido `foo@bar.com` | Normalized lowercase email | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php:65` — `expect(...?->email)->toBe('foo@bar.com')` | ✅ PASS |
| WHEN dois e-mails normalizados iguais THEN segunda falha | Uniqueness violation | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php:72` — `->toThrow(AuthDomainException::class, 'The email address is already in use.')` | ✅ PASS |
| WHEN registro persistido THEN `terms_version`, `terms_accepted_at`, `status` conforme fornecidos | Explicit caller values stored | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php:99` — `expect($model?->status)->toBe('active')` (+ terms assertions) | ✅ PASS |
| WHEN `User` criado THEN `id` UUID v7 gerado na aplicação | RFC 9562 v7 before persist | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php:56` — `->toMatch('/...-7[0-9a-f]{3}-.../')` | ✅ PASS |

### P1: Domínio compartilhado (FND-07)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN `EmailAddress::fromString('  A@B.C  ')` THEN valor `a@b.c` | Trim + lowercase | `backend/modules/Auth/Tests/Unit/EmailAddressTest.php:12` — `expect($email->value())->toBe('a@b.c')` | ✅ PASS |
| WHEN `EmailAddress::fromString('not-an-email')` THEN falha com exceção | Domain exception, no persist | `backend/modules/Auth/Tests/Unit/EmailAddressTest.php:17` — `->throws(AuthDomainException::class, 'The provided email address is invalid.')` | ✅ PASS |
| WHEN `UserStatus` referenciado THEN quatro estados snake_case | Four enum values | `backend/modules/Auth/Tests/Unit/UserStatusTest.php:9` — `expect(UserStatus::cases())->toHaveCount(4)` (+ value assertions) | ✅ PASS |
| WHEN status inválido atribuído THEN falha antes do banco | Reject invalid status | `backend/modules/Auth/Tests/Unit/UserStatusTest.php:17` — `UserStatus::fromString('invalid_status')` `->throws(ValueError::class)` | ✅ PASS |

### P1: Política de senha e hash (AUTH-06–08, FND-08, FND-09)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN senha &lt;12 ou &gt;128 THEN falha `too_short` / `too_long` | Stable violation codes | `backend/modules/Auth/Tests/Unit/PasswordPolicyTest.php:28` — `expect($exception->violationCode())->toBe(PasswordViolationCode::TooShort)`; `:43` — `TooLong` | ✅ PASS |
| WHEN falta categoria THEN código estável por categoria | `missing_*` codes | `backend/modules/Auth/Tests/Unit/PasswordPolicyTest.php:56` — `MissingLowercase`; `:68` — `MissingUppercase`; `:80` — `MissingDigit`; `:92` — `MissingSymbol` | ✅ PASS |
| WHEN senha 12 ou 128 chars com complexidade THEN passa | Inclusive bounds | `backend/modules/Auth/Tests/Unit/PasswordPolicyTest.php:15` — `not->toThrow(PasswordPolicyException::class)` (12 chars); `:21` (128 chars) | ✅ PASS |
| WHEN senha válida hasheada THEN Argon2id verificável, ≠ plaintext | Round-trip verify | `backend/modules/Auth/Tests/Unit/LaravelPasswordHasherTest.php:28` — `expect($this->hasher->verify($plainText, $hash))->toBeTrue()`; `:34` — `not->toBe($plainText)`; `:40` — `toStartWith('$argon2id$')` | ✅ PASS |
| WHEN logs/exceções durante validação/hash THEN sem plaintext | No password in messages | `backend/modules/Auth/Tests/Unit/PasswordPolicyTest.php:126` — `expect($exception->getMessage())->not->toContain($password)` | ✅ PASS |
| WHEN config hashing inspecionada THEN driver `argon2id`, memória ≥64 MiB | Published defaults | — | ⚠️ Spec-precision gap — `LaravelPasswordHasherTest` sets config in `beforeEach`; no test reads `config/hashing.php` defaults (`driver=argon2id`, `memory=65536`) |

**Status**: ❌ Gaps present — 19/25 ACs with matching test evidence; 4 spec-precision gaps; 4 ACs with no test evidence

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `backend/modules/Auth/Domain/Services/PasswordPolicy.php:12` | `MIN_LENGTH = 12` → `13` | ✅ Killed — 6 failures in `PasswordPolicyTest` |
| 2 | `backend/modules/Auth/Domain/ValueObjects/EmailAddress.php:15` | Removed `strtolower()` from normalization | ✅ Killed — 2 failures in `EmailAddressTest` |
| 3 | `backend/modules/Auth/Infrastructure/Persistence/Eloquent/Mappers/UserMapper.php:41` | Uppercased email on persist | ✅ Killed — 2 failures in `EloquentUserRepositoryTest` |

**Sensor depth**: P0-full (manual fault injection; ≥3 mutations on PasswordPolicy, EmailAddress, EloquentUserRepository path)
**Result**: 3/3 killed — ✅ PASS

Mutations applied in scratch state (temp file backup + restore); production tree restored after each run.

---

## Interactive UAT Results (if performed)

Not performed — backend-only foundation slice; automated gates sufficient per spec.

---

## Code Quality

| Principle        | Status |
| ---------------- | ------ |
| Minimum code     | ✅     |
| Surgical changes | ✅     |
| No scope creep   | ✅     |
| Matches patterns | ✅ Hexagonal module layout matches `LARAVEL_CODE_DESIGN.md` |
| Spec-anchored outcome check | ⚠️ 4 ACs lack test evidence; 4 spec-precision gaps |
| Per-layer Coverage Expectation | ✅ Domain 1:1 for password/email/status; integration for repository |
| Every test maps to spec requirement | ✅ Spot-check: no orphan Auth tests found |
| Documented guidelines followed | ✅ `docs/testing.md` §2–§4, `AGENTS.md`, `LARAVEL_CODE_DESIGN.md` §26 |

---

## Edge Cases

- [x] E-mail com espaços externos → trim + lowercase (`EmailAddressTest.php:9-12`)
- [x] E-mail differing only by case → equality + repository duplicate (`EmailAddressTest.php:19-23`, `EloquentUserRepositoryTest.php:72`)
- [x] Senha limite 12/128 → aceita com complexidade (`PasswordPolicyTest.php:14-21`)
- [x] Senha Unicode fora ASCII → categorias ASCII only (`PasswordPolicyTest.php:106-109`)
- [ ] `terms_accepted_at` / `terms_version` ausentes na persistência → **NOT tested** (no NOT NULL / domain rejection test)
- [ ] Status inválido na persistência → **NOT tested at DB** (enum rejection only in `UserStatusTest.php:16`)
- [ ] UUID v4 passado ao repository → **NOT tested at repository** (`UserIdTest.php:15` covers VO only)

---

## Gate Check

- **Gate command**: `make lint && make test-backend && make test-backend-coverage` (per `tasks.md` Build + Coverage gates)
- **Result**: All gates passed — 0 failed, 0 skipped
- **Test count before feature** (`498540a^`): 21 (`9` Feature + `12` Architecture)
- **Test count after feature** (`b1762cb`): 64 (`43` Auth + `9` Feature + `12` Architecture via `php artisan test`)
- **Delta**: +43 new tests
- **Skipped tests**: none
- **Failures**: none

### Coverage (`backend/storage/coverage/modules/Auth/index.html`)

| Metric | Value | Gate (≥80%) |
| ------ | ----- | ----------- |
| Lines | **83.77%** (382/456) | ✅ PASS |
| Methods | **91.30%** (42/46) | ✅ PASS |
| Classes | 73.33% (11/15) | n/a |
| Branches | **Not reported** by PCOV HTML reporter | ⚠️ Cannot verify 80% branch gate from artifact |

Notable low-coverage classes: `UserModelFactory` 0%, `UserModel` 80%, `UserId::equals` 0%.

---

## Fix Plans (if issues found)

### Fix 1: Assert AuthServiceProvider registration at boot

- **Root cause**: Scaffold AC1 has no test tracing provider registration
- **Fix task**: Add Feature test resolving `UserRepository::class` from container (or assert `bootstrap/providers.php` provider list via boot assertion)
- **Priority**: Major

### Fix 2: Migration legacy-removal and schema contract test

- **Root cause**: AC2 (no `remember_token`/legacy tables) and partial AC1 (CHECK constraint) rely on code inspection only
- **Fix task**: Integration test querying `information_schema` / `pg_constraint` on `fake_link_testing` after migrate — assert columns, CHECK on `status`, absence of legacy tables
- **Priority**: Major

### Fix 3: Postgres init smoke for `fake_link_testing`

- **Root cause**: FND-10 AC1 has no automated verification
- **Fix task**: Compose smoke or integration test connecting to Postgres and asserting `fake_link_testing` exists (or document as manual gate with explicit skip justification — prefer automated)
- **Priority**: Major

### Fix 4: Config hashing defaults test

- **Root cause**: Password AC6 not anchored to published `config/hashing.php`
- **Fix task**: Unit test loading default config asserts `driver === 'argon2id'` and `argon.memory >= 65536`
- **Priority**: Minor

### Fix 5: Edge-case persistence failures

- **Root cause**: Spec edge cases for missing terms and invalid status at DB layer untested
- **Fix task**: Integration tests attempting save without required terms (expect SQL/validation failure); invalid enum bypass attempt (expect CHECK failure)
- **Priority**: Minor

### Fix 6: Branch coverage reporting

- **Root cause**: PCOV HTML report does not emit branch coverage; spec success criteria require ≥80% branches
- **Fix task**: Enable branch coverage in PHPUnit/PCOV config or add explicit branch metric to coverage gate
- **Priority**: Minor

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| FND-01 | Done | ⚠️ Verified (provider registration lacks test evidence) |
| FND-02 | Done | ✅ Verified |
| FND-03 | Done | ⚠️ Verified (phpstan path inclusion indirect) |
| FND-04 | Done | ⚠️ Verified (schema partially evidenced) |
| FND-05 | Done | ❌ Needs Fix (no test for legacy removal) |
| FND-06 | Done | ✅ Verified |
| FND-07 | Done | ✅ Verified |
| FND-08 | Done | ✅ Verified |
| FND-09 | Done | ✅ Verified |
| FND-10 | Done | ❌ Needs Fix (init DB existence untested) |
| FND-11 | Done | ⚠️ Verified (doc AC manual) |
| AUTH-06 | Done | ✅ Verified |
| AUTH-07 | Done | ✅ Verified |
| AUTH-08 | Done | ⚠️ Verified (config defaults indirect) |

---

## Summary

**Overall**: ⚠️ Issues — gates green, core domain/repository behavior solid, but evidence-or-zero gaps remain on infra/doc ACs and edge cases

**Spec-anchored check**: 19/25 ACs matched spec outcome; 4 spec-precision gaps flagged; 4 ACs with no test evidence
**Sensor**: 3/3 mutations killed
**Gate**: 64 passed, 0 failed (lint + test-backend + coverage)

**What works**: Password policy with stable codes; email normalization; UUID v7 persistence; duplicate email constraint; Argon2id round-trip; database safety guard; modular arch rules; line coverage 83.77%

**Issues found**:
1. Infra ACs (Postgres init DB, migration legacy removal) lack automated test evidence
2. AuthServiceProvider registration not asserted in tests
3. Config hashing defaults not test-anchored
4. Edge cases (missing terms, invalid status at DB, UUID v4 at repository) untested
5. Branch coverage metric unavailable — 80% branch gate unverified

**Next steps**: Execute Fix 1–3 (Major) before marking foundation fully verified; Fix 4–6 as follow-up; re-run Verifier after fixes
