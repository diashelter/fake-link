# Auth / email-verification Validation

**Date**: 2026-07-27  
**Spec**: `.specs/features/auth/email-verification/spec.md`  
**Diff range**: `885e65c^..1bbb3ca` (`feature/auth-email-verification`)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1–T17 | ✅ Done | Committed in Execute range |
| T18 | ⚠️ Partial | Gates/OpenAPI done; Verifier checkbox left unchecked (FAIL) |

---

## Spec-Anchored Acceptance Criteria

### Catalog + slice IDs

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| **AUTH-12 / EV-07 / verify AC1–5**: verify success → 204, `active`, `email_verified_at`, `used_at`, revoke presented bearer, no session body | HTTP 204 empty; status `active`; bearer gone; login emits `session` | `EmailVerificationTest.php:70-93` — `assertNoContent()`, `status`=`active`, `email_verified_at`=`2026-07-27T18:00:00+00:00`, `remainingBearers`=`0`, login `token_kind`=`session`; `VerifyUserEmailTest.php:122-128` — revoke + session count unchanged | ✅ PASS |
| **AUTH-20 / EV-01**: RegisterUser success creates `email_action_tokens` purpose=`email_verification`, TTL 3600, hash only | row + purpose + `expires_at=now+3600` + hash≠plaintext | `IssueEmailVerificationTokenTest.php:44-51` — `purpose`=`email_verification`, `expires_at`=`…T01:00:00+00:00`, hash match; `RegisterUserTest.php:227-230` — unused token count `1` + job pushed; `RegistrationTest.php:91-94` — same | ✅ PASS |
| **AUTH-20 / EV-02**: Job sends mail via Laravel/Resend transport with URL+token in body only (not subject) | `Mail::fake` sent; URL config; subject without sentinel | `SendEmailVerificationJobTest.php:52-60` — `hasTo`, `verificationUrl` exact, `subject`=`Confirme seu e-mail — Fake Link`, `!str_contains(subject, sentinel)` | ✅ PASS |
| **AUTH-20 / AUTH-25 / EV-03 / EV-11**: plaintext not in logs/failed_jobs/traces; queue payload ciphertext | serialized job lacks plaintext; decrypt fail message fixed | `SendEmailVerificationJobTest.php:72-73`, `:90-91` — `serialize`/`getMessage` not contain sentinel; `LaravelQueueEmailVerificationTest.php:51-58` — ciphertext decryptable | ✅ PASS |
| **AUTH-20 AC4**: transient job retry; permanent failure does not alter `users.status` | retry per Laravel; status unchanged | `RegisterUserTest.php:300-307` — queue dispatch fail keeps `pending_verification`; job `handle` never mutates status (structural). No explicit `$tries`/retry assertion | ⚠️ Spec-precision gap (retry config not asserted) |
| **AUTH-21**: TTL 60 min absolute | `expires_at = now + 3600s` | `IssueEmailVerificationTokenTest.php:44-45` — `absoluteTtlSeconds()`=`3600`, expires `+1h` | ✅ PASS |
| **AUTH-22 / verify AC6**: invalid/expired/used/wrong-user → `403 INVALID_VERIFICATION_TOKEN` + exact message | code + message | `EmailVerificationTest.php:109-111` — `code`/`message` exact; `VerifyUserEmailTest.php:133-220` — expired/used/cross-user throw `InvalidVerificationTokenException` | ✅ PASS |
| **AUTH-23 / EV-04 / resend AC1–3**: POST resend → `202`, new token, invalidate previous, enqueue once | 202 + unused=1 + job on `notifications` | `EmailVerificationTest.php:231-239` — `assertAccepted()`, unused count `1`, `Queue::assertPushed(..., 1)`; `ResendEmailVerificationTest.php:86-91` — previous `used_at` set, new unused | ✅ PASS |
| **AUTH-23 / resend AC4**: missing/invalid/session bearer → `401 UNAUTHENTICATED` or `403 TOKEN_RESTRICTED` on **resend** route | same codes on `POST …/verification-notification` | no `file:line` on resend route (verify-only: `EmailVerificationTest.php:158-175`; probe middleware: `BearerMiddlewareTest.php`) | ❌ GAP |
| **AUTH-23 / resend AC5**: suspended / deletion_pending → `403 ACCOUNT_*` on **resend** route | `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | no `file:line` on email-verification routes (only probe: `BearerMiddlewareTest.php:105-118`) | ❌ GAP |
| **AUTH-23 / EV-05 / resend AC6–7**: 4th resend/h → `429 RATE_LIMIT_EXCEEDED` + `Retry-After`; hit before use case | 429 + Retry-After; attempts counted | `EmailVerificationTest.php:274-277`; `ThrottleEmailVerificationTest.php:58-74`, verify hit-before `:112-131` (resend hit-before not separately asserted; same middleware pattern) | ✅ PASS (verify hit-before strong; resend covered by 4th→429) |
| **AUTH-24 / verify AC4**: revoke only presented bearer | bearer id deleted | `VerifyUserEmailTest.php:126` — `AuthTokenModel::where('id', bearerTokenId)->exists()`=`false` | ✅ PASS |
| **AUTH-12 / verify AC5**: no session issuance on verify | no session token / empty body | `EmailVerificationTest.php:74`, `:91-93`; `VerifyUserEmailTest.php:128` | ✅ PASS |
| **EV-08 / verify AC3**: atomic `used_at` | `used_at` set in txn | `VerifyUserEmailTest.php:124`; concurrency `EloquentEmailActionTokenRepositoryTest.php:278-279` — `[true, false]` | ✅ PASS |
| **EV-09 / verify AC7**: GET must not verify | 405 + status unchanged | `EmailVerificationTest.php:211-214` — status `405`, user still `pending_verification` | ✅ PASS |
| **EV-10 / verify AC8–9**: 6th verify → 429; validation → 422 without consume | 429 / `VALIDATION_FAILED` | `EmailVerificationTest.php:198-201`, `:142-155` | ✅ PASS |
| **EV-12**: already `active` → `403 EMAIL_ALREADY_VERIFIED` (verify + resend) | code exact | `EmailVerificationTest.php:128-130`, `:251-252`; integration exceptions | ✅ PASS |
| **EV-12 AC3 / P2**: errors include `Cache-Control: private, no-store` + `request_id` | headers present | `EmailVerificationTest.php:71-73`, `:113`, `:234-235`; factory unit tests | ✅ PASS |
| **AUTH-25 AC2**: servers must not log query string with email token | access-log redaction / path-only | no app/nginx test evidence in slice | ⚠️ Spec-precision gap (infra outside API suite) |
| **EV-13 / EV-14**: discovered by `make test-backend`; final `make lint && make test-backend` green | suite green | Gate this run: lint PASS; **283 passed**, 0 failed | ✅ PASS |

**Status**: ❌ Gaps present (resend auth ACCOUNT_*/401/TOKEN_RESTRICTED on route) + ⚠️ Spec-precision gaps flagged

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| M1 | `UseCases/VerifyUserEmail.php:55` | Removed `revokeAuthToken->byId(...)` | ✅ Killed |
| M2 | `Domain/Enums/EmailActionPurpose.php:12` | TTL `3600` → `7200` | ✅ Killed |
| M3 | `ThrottleEmailVerificationResend.php:28` | Hardcoded `maxAttempts = 30` | ✅ Killed |
| M4 | `UseCases/IssueEmailVerificationToken.php:31` | Skipped `invalidateUnusedForUser` | ✅ Killed |
| M5 | `Exceptions/InvalidVerificationTokenException.php:24` | Message → `Bad token.` | ❌ Survived |
| M6 | `ThrottleEmailVerificationVerify.php:28` | Hardcoded `maxAttempts = 50` | ✅ Killed |

**Sensor depth**: P0-full (6 manual behavior-level mutations; auth critical path)  
**Scratch**: temporary patches on worktree/main with immediate `git checkout --` restore; tree clean after sensor  
**Result**: 5/6 killed, **1 survived** — FAIL ❌

**Surviving mutant notes**: HTTP message is hardcoded in `AuthErrorResponseFactory::invalidVerificationToken` (asserted). Exception `getMessage()` is only checked for sentinel absence (`AuthErrorResponseFactoryTest.php:144`), not equality to the OpenAPI string — domain exception text can drift undetected.

---

## Interactive UAT Results

N/A — backend API-only slice; automated gates + sensor sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ |
| Spec-anchored outcome check | ❌ (resend auth ACs; exception message coupling) |
| Per-layer Coverage Expectation (routes happy+edge+error) | ❌ resend route missing authz/account error paths |
| Every test maps to a spec requirement | ✅ (feature tests map to ACs; no orphan critical paths spotted) |
| Documented guidelines followed | ✅ `docs/testing.md`, `LARAVEL_CODE_DESIGN.md` |

---

## Edge Cases

- [x] Resend invalidates prior unused token before expiry — `ResendEmailVerificationTest.php:86-88`
- [x] Concurrent consume — one wins — `EloquentEmailActionTokenRepositoryTest.php:251-279`
- [ ] Expired bearer between resend and verify without consuming email token — **no dedicated test**
- [x] Job/dispatch failure after register keeps pending — `RegisterUserTest.php:300-307`
- [x] Cross-user email token → invalid — `VerifyUserEmailTest.php:208-220`
- [ ] Whitespace-only token rejected by HTTP validation (no trim) — **not asserted** (`min:1` accepts `" "`)
- [x] Mail sent with configured URL — `SendEmailVerificationJobTest.php:52-60`

---

## Gate Check

- **Gate command**: `make lint && make test-backend` (Final / Build from tasks.md)
- **Result**: lint PASS; **283** passed, **0** failed, **0** skipped
- **Test count before feature** (login validation): 225
- **Test count after feature**: 283
- **Delta**: +58
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

### Fix 1: Resend route auth / account status E2E (Blocker for AC coverage)

- **Root cause**: `POST /email/verification-notification` lacks feature assertions for missing bearer, `TOKEN_RESTRICTED`, `ACCOUNT_SUSPENDED`, `ACCOUNT_PENDING_DELETION` (middleware wired but unproven on this route).
- **Fix task**: Add Feature cases in `EmailVerificationTest.php` mirroring verify auth boundaries + suspended/deletion_pending with verification bearer.
- **Verify**: assert 401/403 codes on resend URL; Queue nothing pushed.
- **Priority**: Major

### Fix 2: Bind domain exception message to OpenAPI string (sensor M5)

- **Root cause**: Tests assert factory message and exception `errorCode`, but not `InvalidVerificationTokenException::invalid()->getMessage()` (and likely `EmailAlreadyVerifiedException`) equality to OpenAPI.
- **Fix task**: Assert exact `getMessage()` in `AuthErrorResponseFactoryTest` (or exception unit test).
- **Verify**: Re-run sensor M5 — must kill.
- **Priority**: Major (surviving mutant)

### Fix 3 (optional): Whitespace token + expired-bearer edge

- **Root cause**: Edge cases listed in spec without tests.
- **Fix task**: 422 (or documented reject) for whitespace-only token; verify with expired bearer leaves email token unused.
- **Priority**: Minor

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| AUTH-12 | Approved | ✅ Verified (tests) |
| AUTH-20 | Approved | ⚠️ Partial (retry precision) |
| AUTH-21 | Approved | ✅ Verified |
| AUTH-22 | Approved | ✅ Verified |
| AUTH-23 | Approved | ❌ Needs Fix (resend AC4–5) |
| AUTH-24 | Approved | ✅ Verified |
| AUTH-25 | Approved | ⚠️ Spec-precision (access logs) |
| EV-01…EV-03 | Approved | ✅ Verified |
| EV-04 | Approved | ❌ Needs Fix (auth boundary on resend) |
| EV-05…EV-12 | Approved | ✅ Verified (EV-05 pass) |
| EV-13, EV-14 | Pending | ✅ Verified (gate) |

---

## Summary

**Overall**: ❌ Not Ready

**Spec-anchored check**: 18/21 precise ACs matched; **2 AC gaps** (resend auth/account); **2 spec-precision gaps** (job retry assertion; access-log redaction)  
**Sensor**: 5/6 killed, **1 survived** (exception message drift)  
**Gate**: 283 passed, 0 failed

**What works**: Happy verify/resend, TTL, invalidate-on-reissue, rate limits 3/h & 5/h, revoke bearer, no session on verify, Mail ciphertext, OpenAPI error codes on verify path, registration pipeline wiring, final gate green.

**Issues found**: Resend route error-path coverage gap; surviving mutant on exception message; infra access-log AC not testable in suite.

**Next steps**: Implement Fix 1 + Fix 2; re-run Verifier (iteration 1/3).
