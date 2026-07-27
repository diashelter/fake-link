# Auth Login Validation

**Date**: 2026-07-27
**Spec**: `.specs/features/auth/login/spec.md`
**Diff range**: `ac2527f^..90ad3a3` (T1–T13)
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | `findByEmail` + integration tests |
| T2 | ✅ Done | `InvalidCredentialsException` + factory |
| T3 | ✅ Done | login rate limits + dummy hash config |
| T4 | ✅ Done | HMAC login keys |
| T5 | ✅ Done | `ThrottleLogin` dual middleware |
| T6 | ✅ Done | Login DTOs |
| T7 | ✅ Done | `LoginUser` + integration matrix |
| T8 | ✅ Done | `LoginUserRequest` (verified via T12) |
| T9 | ✅ Done | `authenticated()` → 200 |
| T10 | ✅ Done | controller + route + provider |
| T11 | ✅ Done | factory states |
| T12 | ✅ Done | Feature E2E `LoginTest.php` |
| T13 | ✅ Done | gate green; Verifier complete |

---

## Spec-Anchored Acceptance Criteria

### P1: Login bem-sucedido por status da conta

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN valid credentials for `active` THEN 200 AuthResponse | HTTP 200 + AuthResponse body | `LoginTest.php:85-92` — `assertOk()` + `assertJsonPath('data.token_kind', 'session')` + user fields | ✅ PASS |
| WHEN active login THEN `token_kind=session`, `token_type=Bearer`, `expires_at` = now+604800s | session / Bearer / `2026-08-03T12:00:00Z` at frozen now | `LoginTest.php:86-88` — `assertJsonPath('data.token_type', 'Bearer')`, `token_kind` session, `expires_at` | ✅ PASS |
| WHEN pending_verification login THEN `token_kind=verification`, expires now+86400s | verification / `2026-07-28T12:00:00Z` | `LoginTest.php:128-131` — `assertOk()` + `token_kind` verification + `expires_at` | ✅ PASS |
| WHEN successful login THEN plaintext only in response body; DB stores hash only | token string in body ≠ `token_hash` | `LoginTest.php:94-105` — `$token` string/non-empty; `token_hash` `not->toBe($token)` | ✅ PASS |
| WHEN successful login THEN `data.user` reflects AuthUserResource incl. status | user id/email/status/name | `LoginTest.php:89-92` — `data.user.*` paths | ✅ PASS |
| WHEN successful login THEN preexisting tokens not revoked | count → 2 after second login | `LoginTest.php:284-286` — distinct tokens + `count()->toBe(2)`; also `LoginUserTest.php:221-225` | ✅ PASS |
| WHEN pending_verification login THEN `QueueEmailVerification` NOT invoked | no queue jobs | `LoginTest.php:140-141` — `Queue::assertNothingPushed()` / `assertNotPushed(SendEmailVerificationJob)`; `LoginUserTest.php:144-145` | ✅ PASS |

### P1: Credenciais inválidas

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN unknown email THEN 401 `INVALID_CREDENTIALS` + exact message | 401 / code / `The provided credentials are invalid.` | `LoginTest.php:56-60` via `assertInvalidCredentialsEnvelope($unknown)` at `:175` | ✅ PASS |
| WHEN wrong password THEN same 401 code+message as unknown email | identical envelopes | `LoginTest.php:179-186` — unknown vs wrongPassword arrays equal | ✅ PASS |
| WHEN wrong password on suspended/deletion_pending THEN 401 not 403 | 401 INVALID_CREDENTIALS | `LoginTest.php:175-177` suspended; `:244-256` deletion_pending; `LoginUserTest.php:177-178` | ✅ PASS |
| WHEN 401 INVALID_CREDENTIALS THEN no user/token body hints | no `data`/`token`/`user` keys | `LoginTest.php:62-64` | ✅ PASS |
| WHEN email missing THEN still `PasswordHasher::verify` against dummy hash | verify called with `auth.dummy_password_hash` | `LoginUserTest.php:157-159` — `verifyCalls` count 1 + hash = config dummy | ✅ PASS |
| WHEN invalid credential THEN no token issued/persisted | `auth_tokens` count 0 | `LoginTest.php:197`; `LoginUserTest.php:161,171,181` | ✅ PASS |

### P1: Bloqueio por status da conta

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN correct password + suspended THEN 403 ACCOUNT_SUSPENDED + message | 403 / code / `The account is suspended.` | `LoginTest.php:212-214`; `LoginUserTest.php:191-192` | ✅ PASS |
| WHEN correct password + deletion_pending THEN 403 ACCOUNT_PENDING_DELETION + message | 403 / code / `The account is pending deletion.` | `LoginTest.php:234-236`; `LoginUserTest.php:206-207` | ✅ PASS |
| WHEN status-blocked THEN no token issued | token count 0 | `LoginTest.php:219,241`; `LoginUserTest.php:196,211` | ✅ PASS |
| WHEN status-blocked THEN not 401 INVALID_CREDENTIALS | code ≠ INVALID_CREDENTIALS | `LoginTest.php:217,239` | ✅ PASS |

### P1: Validação de entrada HTTP

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN extra JSON fields THEN 422 VALIDATION_FAILED, no token | 422 + code + errors.role | `LoginTest.php:295-300` | ✅ PASS |
| WHEN email or password absent THEN 422 VALIDATION_FAILED | 422 + code (incl. empty password) | `LoginTest.php:315-317` | ✅ PASS |
| WHEN email invalid or >254 THEN 422 VALIDATION_FAILED | 422 + code | `LoginTest.php:342-343` | ✅ PASS |
| WHEN password >128 THEN 422 VALIDATION_FAILED | 422 + code | `LoginTest.php:344` | ✅ PASS |
| WHEN validation fails THEN no auth_tokens | count 0 | `LoginTest.php:300,320,347` | ✅ PASS |
| WHEN missing/non-JSON Content-Type or malformed JSON THEN 400 MALFORMED_REQUEST | 400 + code + message | `LoginTest.php:366-368,389-391,413-415` | ✅ PASS |

### P1: Rate limiting

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN email+IP exceeds 5/60s THEN 429 RATE_LIMIT_EXCEEDED + Retry-After | 6th → 429 + header | `LoginTest.php:442-452`; `ThrottleLoginTest.php:54-60` | ✅ PASS |
| WHEN IP exceeds 30/60s THEN 429 + Retry-After | 31st → 429 | `LoginTest.php:477-488`; `ThrottleLoginTest.php:93-95` | ✅ PASS |
| WHEN either limit exceeded THEN 429 before authentication | limited without invoking next | `ThrottleLoginTest.php:123-126` — `$invocations` stays 5 on 429 | ✅ PASS |
| WHEN rate limit applied THEN HMAC keys with `login:email-ip:` / `login:ip:` prefixes, no raw IP/email | digests match purpose prefixes | `HmacRateLimitKeyFactoryTest.php:49-56,80-86` | ✅ PASS |
| WHEN rate limit fires THEN no additional token | token count 0 | `LoginTest.php:454,490` | ✅ PASS |
| WHEN attempts include 422/401/403/200 THEN all count toward both limits | hits both keys before handler (any status) | `ThrottleLoginTest.php:119-121` — both keys = 5 after 422 responses; middleware hits pre-controller | ✅ PASS |

### P2: Contrato HTTP e descoberta de testes

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN route inspected THEN POST `/api/v1/auth/login` registered with thin controller + Form Request | route + middleware | `auth.php:12-13` — `Route::post('/login', LoginUserController::class)->middleware('throttle.login')`; exercised by Feature suite | ✅ PASS |
| WHEN `make test-backend` THEN Feature `LoginTest.php` discovered | executed in gate | Gate run: `Modules\Auth\Tests\Feature\LoginTest` PASS | ✅ PASS |
| WHEN response is 200 THEN `Cache-Control: private, no-store` and `X-Request-ID` | headers present | `LoginTest.php:96-98`; `AuthResponseFactoryTest.php:88-91` | ✅ PASS |

**Status**: ✅ All ACs covered (32/32) — 0 spec-precision gaps

---

## Discrimination Sensor

Scratch mutations applied in-place then discarded via `git checkout` (worktree unavailable in sandbox; tree verified clean after each restore). Focused Pest filters via Docker.

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `LoginUser.php:31-37` | Skip dummy `PasswordHasher::verify` when user missing | ✅ Killed — `LoginUserTest.php:157` (verifyCalls size 0≠1) |
| 2 | `LoginUser.php` (status before password) | Return `ACCOUNT_SUSPENDED` before password check | ✅ Killed — `LoginUserTest.php:178` (wrong password on suspended expects InvalidCredentials) |
| 3 | `LoginUser.php:47` | `PendingVerification` → `TokenKind::Session` | ✅ Killed — `LoginUserTest.php:140` |
| 4 | `AuthResponseFactory.php:49` | `authenticated` returns HTTP 201 instead of 200 | ✅ Killed — `AuthResponseFactoryTest.php:88` |
| 5 | `ThrottleLogin.php:49-50` | Hit only email-ip key (skip IP hit) | ✅ Killed — `ThrottleLoginTest.php:121` |
| 6 | `LoginUser.php` (pre-issue) | Delete prior `auth_tokens` on login (revoke) | ✅ Killed — `LoginUserTest.php:223` + `LoginTest.php:286` |

**Sensor depth**: P0-full (≥5 behavior-level)
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results

Skipped — backend API-only feature; automated Feature/Integration/Unit coverage sufficient.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ (mirrors registration: Form Request, dual throttle, response factories) |
| Spec-anchored outcome check | ✅ |
| Per-layer Coverage Expectation met | ✅ unit / integration / feature per tasks matrix |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §6.1, `LARAVEL_CODE_DESIGN.md`, `AGENTS.md` | ✅ |

---

## Edge Cases

- [x] Email case/whitespace normalized before lookup — `LoginTest.php:81` (`  Active@Example.com  `) → user email `active@example.com`; `EloquentUserRepositoryTest.php:142-152`
- [x] Multiple active logins → multiple session tokens coexist — `LoginTest.php:261-286`
- [x] pending_verification re-login → new verification token; prior remain — multi-token + pending kind tests
- [x] suspended/deletion_pending + wrong password → 401 — Feature + Integration
- [x] suspended/deletion_pending + correct password → 403 specific — Feature + Integration
- [x] Empty password `""` → 422 — `LoginTest.php:310-317`
- [x] 413 body > 64 KiB — out of slice scope (infra); not crashed by login code
- [~] IssueAuthToken failure after valid password → 500 without partial token — controller maps Throwable/`AuthTokenException` default to 500 (`LoginUserController.php:35-38,44-52`); **no dedicated automated test** (edge observation, not numbered AC gap)
- [x] Token plaintext absent from error bodies — 401/403 envelopes lack token (`LoginTest.php:62-64,216`)
- [x] Login does not alter status / email_verified_at — `LoginTest.php:137-138`; `LoginUserTest.php:128,142`
- [x] pending_verification login does not queue verification email — Queue asserts above

---

## Gate Check

- **Gate command**: `make lint && make test-backend`
- **Result**: lint PASS (pint/phpstan/phpmd); **225 passed**, 0 failed, 0 skipped (801 assertions)
- **Test count before feature** (registration validation baseline): 190
- **Test count after feature**: 225
- **Delta**: +35
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — clean PASS.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| AUTH-09 | Implemented | ✅ Verified |
| AUTH-10 | Implemented | ✅ Verified |
| AUTH-11 | Implemented | ✅ Verified |
| LOG-01 … LOG-12 | Implemented | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 32/32 ACs matched spec outcome | 0 spec-precision gaps
**Sensor**: 6/6 mutations killed
**Gate**: 225 passed

**What works**: Dual-status token issuance, anti-enumeration 401 (incl. dummy verify), status 403 after correct password, validation 422/400, dual HMAC rate limits, multi-session, no email queue on pending login, OpenAPI-aligned 200 AuthResponse headers.

**Issues found**: None blocking. Optional follow-up: add a focused test that `IssueAuthToken` failure after valid credentials yields `500 INTERNAL_ERROR` with zero new tokens (edge case only).

**Next steps**: Mark feature Verified in index/STATE as orchestrator prefers; no fix loop.
