# Auth — Registro por convite — Validation

**Date**: 2026-07-26  
**Spec**: `.specs/features/auth/registration/spec.md`  
**Diff range**: `66e2b09^..97442eb` (`auth/registration`)  
**Verifier**: independent sub-agent (author ≠ verifier)  
**Iteration**: re-verify 2/3 after Fix loop 2 (`1365ad0`, `97442eb`)

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1–T15 | ✅ Done | All Done-when `[x]` in tasks.md |
| Fix loop 1 | ✅ Applied | Validation matrix, MALFORMED_REQUEST mapping, edge HTTP, allowlist Log spy |
| Fix loop 2 | ✅ Applied | Non-JSON Content-Type rejection + Feature/Unit assertions |

---

## Spec-Anchored Acceptance Criteria

### P1: Registro bem-sucedido com convite válido

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Valid allowlisted payload → AuthResponse | HTTP `201`; AuthResponse fields | `RegistrationTest.php:58-66` — `assertCreated()` + `assertJsonPath('data.token_type'/'token_kind'/'expires_at'/'user.*')` | ✅ PASS |
| Persisted user fields | `status=pending_verification`, `email_verified_at=null`, `terms_version=2026-01`, `terms_accepted_at` UTC | `RegistrationTest.php:63-66,81-83`; `RegisterUserTest.php:178-181` | ✅ PASS |
| Password stored as Argon2id hash | Persisted hash only (Argon2id); never plaintext | `RegisterUserTest.php:188-189` — `not->toBe(...)` + `Hash::check`; `LaravelPasswordHasherTest.php:37-40` — `toStartWith('$argon2id$')`; `HashingConfigTest.php:10-11` | ✅ PASS |
| Token kind/type/expiry | `token_kind=verification`, `token_type=Bearer`, `expires_at=now+86400s` | `RegistrationTest.php:59-61` (`2026-07-27T12:00:00Z`); `RegisterUserTest.php:182-183` | ✅ PASS |
| Plaintext token only in 201 body; DB hash | Plaintext in `data.token` only; `auth_tokens.token_hash` ≠ plaintext | `RegistrationTest.php:68-88`; `RegisterUserTest.php:190-191` | ✅ PASS |
| QueueEmailVerification once | Port/job invoked exactly once | `RegistrationTest.php:90-91` — `Queue::assertPushed(..., 1)` + `assertPushedOn('notifications', ...)`; `RegisterUserTest.php:192` | ✅ PASS |

### P1: Allowlist de convite

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Email not on allowlist | `403` + `code=REGISTRATION_NOT_ALLOWED` + generic message | `RegistrationTest.php:109-114` | ✅ PASS |
| Case/trim/non-normalized outside list → 403; listed with trim/case → success | Outside → 403; listed normalized → success | Outside: `RegistrationTest.php:96-114`; listed: `RegistrationTest.php:54-58` + `JsonFileInviteAllowlistTest.php:31-36` | ✅ PASS |
| Allowlist consultation MUST NOT log emails | No email in log/trace/metric | `JsonFileInviteAllowlistTest.php:94-112` — `Log::listen` / `MessageLogged`; asserts `$event->message` and `json_encode($event->context)` omit consulted email | ✅ PASS |
| Allowlist unavailable | `503 SERVICE_UNAVAILABLE` without list leak | `RegistrationTest.php:297-302` — `assertStatus(503)` + `code=SERVICE_UNAVAILABLE` + body omits `invite`/`allowlist` | ✅ PASS |

### P1: Anti-enumeração no registro

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Existing account → same as not invited | `403 REGISTRATION_NOT_ALLOWED` same body/status | `RegistrationTest.php:102-124` | ✅ PASS |
| Invalid invite vs duplicate identical public envelope | Identical `code`, `message`, HTTP status | `RegistrationTest.php:116-124` | ✅ PASS |
| 403 body has no user/token/reason leak | No `data`/token/specific reason | `RegistrationTest.php:125-128,347-349` | ✅ PASS |

### P1: Validação de entrada HTTP

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Extra JSON fields | `422 VALIDATION_FAILED`; no user persisted | `RegistrationTest.php:257-268` — `assertUnprocessable()` + `errors.role` + count `0` | ✅ PASS |
| `accept_terms` absent / false / non-boolean | `422 VALIDATION_FAILED` | false: `147-154`; omitted: `206-214`; non-boolean `'yes'`: `222-229` | ✅ PASS |
| Password length/composition violated | `422 VALIDATION_FAILED` with field errors | Length: `131-139`; composition unit: `PasswordPolicyRuleTest.php` | ✅ PASS |
| `password` ≠ `password_confirmation` | `422 VALIDATION_FAILED` | `RegistrationTest.php:160-168` — mismatch → `assertUnprocessable()` + `VALIDATION_FAILED` + zero rows | ✅ PASS |
| `name` empty or >120 chars | `422 VALIDATION_FAILED` | empty: `176-183`; >120: `191-198` | ✅ PASS |
| `email` invalid or >254 chars | `422 VALIDATION_FAILED` | invalid: `271-278`; >254: `237-249` (`strlen > 254` then 422) | ✅ PASS |
| Validation failure → no `users`/`auth_tokens` rows | Zero rows | Asserted across matrix cases (e.g. `171-173`, `186-188`, `251-254`) | ✅ PASS |

### P1: Rate limiting

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| >5 POSTs/IP/hour → 429 + Retry-After | 6th → `429 RATE_LIMIT_EXCEEDED` + `Retry-After` | `RegistrationTest.php:324-334`; `ThrottleRegistrationTest.php` | ✅ PASS |
| Key derived by IP via HMAC Redis family | HMAC-SHA256; purpose `registration:`; raw IP absent | `HmacRateLimitKeyFactoryTest.php:15-35` | ✅ PASS |
| 6th attempt creates no additional user | No extra user | `RegistrationTest.php:336` — `UserModel::count()->toBe(0)` | ✅ PASS |

### P2: Contrato HTTP e descoberta de testes

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Route registered with thin controller / Form Request / Resource | `POST /api/v1/auth/register` wired | Exercised by Feature suite; factories in `AuthResponseFactoryTest.php` | ✅ PASS |
| `make test-backend` discovers Registration Feature tests | Discovered and executed | Gate: `RegistrationTest` executed; 190 passed | ✅ PASS |
| 201 headers | `Cache-Control: private, no-store` + `X-Request-ID` | `RegistrationTest.php:70-72` | ✅ PASS |

**Prior ranked gaps (Fix loop 2) — re-check**

| # | Gap | Evidence after fix | Result |
| - | --- | ------------------ | ------ |
| 1 | Missing/wrong Content-Type → 400 MALFORMED_REQUEST | Feature: `RegistrationTest.php:412-434` (missing), `436-459` (text/plain); Unit: `RejectMalformedJsonTest.php:14-35`; impl: `RejectMalformedJson.php:27-29` | ✅ |

**Status**: ✅ All ACs covered

---

## Edge Cases

| Edge case | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| Allowlisted email with unlisted `+alias` | `403 REGISTRATION_NOT_ALLOWED` | `RegistrationTest.php:352-360` — `assertForbidden()` + code/message | ✅ PASS |
| Concurrent POSTs same email | At most one `201`; others `403` uniform | `RegisterUserTest.php:222-236` | ✅ PASS |
| Allowlist unavailable | `503` without list leak | `RegistrationTest.php:297-302` | ✅ PASS |
| Token issue failure after user persist | Transactional rollback + `403` | `RegisterUserTest.php:246-261` | ✅ PASS |
| Unicode password outside ASCII categories | `422` | `RegistrationTest.php:368-376` | ✅ PASS |
| Missing Content-Type **or** malformed JSON → `400 MALFORMED_REQUEST` | `400` + no side effects | Malformed: `RegistrationTest.php:384-409` ✅; missing CT: `412-434` ✅; wrong CT: `436-459` ✅; unit: `RejectMalformedJsonTest.php:14-35` ✅ | ✅ PASS |
| Queue dispatch failure after commit | HTTP `201` kept | `RegisterUserTest.php:264-271` | ✅ PASS |
| Plaintext token never in exceptions/logs/error bodies | Absent from error bodies | `RegistrationTest.php:339-349` | ✅ PASS (body) |

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `RejectMalformedJson.php:27-29` | Removed Content-Type gate (`requiresJsonContentType && !isJson` throw) | ✅ Killed (4 failed) |
| 2 | `RegisterUser.php:40` | Flipped allowlist check `!isInvited` → `isInvited` | ✅ Killed (3 failed) |
| 3 | `RegisterUser.php:60` | `UserStatus::PendingVerification` → `UserStatus::Active` | ✅ Killed (2 failed) |
| 4 | `ThrottleRegistration.php:33` | Removed `RateLimiter::hit` | ✅ Killed (3 failed) |
| 5 | `PasswordPolicyRule.php:13-28` | Always-return (accept any password) | ✅ Killed (4 failed) |
| 6 | `ApiResponse.php:30` | `MALFORMED_REQUEST` code → `VALIDATION_FAILED` | ✅ Killed (4 failed) |

**Sensor depth**: P0-full (6 behavior-level mutations; includes Content-Type / RejectMalformedJson + RegisterUser / throttle / password)  
**Scratch method**: temp file backup → mutate → focused Pest via Docker → restore; `git diff --stat` on targets empty after run  
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results

Not performed — backend API slice; automated checks sufficient per validate.md.

---

## Code Quality

| Principle | Status | Notes |
| --------- | ------ | ----- |
| Minimum code | ✅ | Hexagonal ports/adapters; thin controller |
| Surgical changes | ✅ | Auth registration + global ApiResponse/RejectMalformedJson Content-Type gate |
| No scope creep | ✅ | Resend/email-verification deferred |
| Matches patterns | ✅ | UseCase + Form Request + factories |
| Spec-anchored outcome check | ✅ | All story ACs + Content-Type edge assert exact status/code |
| Per-layer Coverage Expectation | ✅ | Unit/integration/feature matrix complete including Content-Type branch |
| Every test maps to a spec requirement | ✅ | Primary suites map to REG/AUTH ACs or Done-when |
| Documented guidelines followed | ✅ | `docs/testing.md` §2/§6.1; `LARAVEL_CODE_DESIGN.md` |

---

## Gate Check

- **Gate command**: `make lint && make test-backend`
- **Result**: lint PASS; **190 passed**, 0 failed, 0 skipped (549 assertions)
- **Test count before feature** (approx at `92c8a87`): ~133–136
- **Test count after Fix loop 2**: 190
- **Delta**: ~+54–57 vs pre-feature baseline; +6 vs prior FAIL report (184 → 190)
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — clean PASS.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| AUTH-01 … AUTH-05 | Verified (story ACs); Content-Type residual | ✅ Verified |
| REG-01 … REG-10 | Verified | ✅ Verified |
| Edge: Content-Type → 400 | Needs Fix (re-verify 1/3) | ✅ Verified |

*(Do not mutate `spec.md` from Verifier — statuses recorded here for orchestrator.)*

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 26/26 story ACs matched; 8/8 edge cases matched (incl. Content-Type missing/wrong + malformed JSON → 400)  
**Sensor**: 6/6 mutations killed  
**Gate**: 190 passed, 0 failed  

**What works**: Full REG-07 Feature matrix; allowlist Log spy; +alias/Unicode/malformed-JSON/missing-and-wrong-Content-Type HTTP outcomes; happy path, anti-enumeration, throttle, Argon2id, queue-once; P0 mutants including Content-Type gate all killed.

**Issues found**: none

**Next steps**: Feature ready — close Execute / proceed per orchestrator (no Fix loop 3).

**Lessons**: clean PASS → no new lesson recorded; prior L-023 (Content-Type OR-path) resolved by Fix loop 2 — not re-added.
