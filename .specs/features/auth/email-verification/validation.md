# Auth / email-verification Validation

**Date**: 2026-07-27  
**Spec**: `.specs/features/auth/email-verification/spec.md`  
**Diff range**: `885e65c^..HEAD` (`feature/auth-email-verification`, includes `b67d4f4`)  
**Verifier**: independent sub-agent (author ≠ verifier)  
**Iteration**: re-verify 1/3 (after Fix 1–2)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1–T17 | ✅ Done | Committed in Execute range |
| T18 | ✅ Done | Gates/OpenAPI done; Verifier checkbox checked on PASS |

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
| **AUTH-22 / verify AC6**: invalid/expired/used/wrong-user → `403 INVALID_VERIFICATION_TOKEN` + exact message | code + message | `EmailVerificationTest.php:109-111` — `code`/`message` exact; `AuthErrorResponseFactoryTest.php:110-113` — `getMessage()`=`The verification token is invalid or has expired.`; `VerifyUserEmailTest.php:133-220` — expired/used/cross-user throw | ✅ PASS |
| **AUTH-23 / EV-04 / resend AC1–3**: POST resend → `202`, new token, invalidate previous, enqueue once | 202 + unused=1 + job on `notifications` | `EmailVerificationTest.php:231-239` — `assertAccepted()`, unused count `1`, `Queue::assertPushed(..., 1)`; `ResendEmailVerificationTest.php:86-91` — previous `used_at` set, new unused | ✅ PASS |
| **AUTH-23 / resend AC4**: missing/invalid/session bearer → `401 UNAUTHENTICATED` or `403 TOKEN_RESTRICTED` on **resend** route | same codes on `POST …/verification-notification` | `EmailVerificationTest.php:257-274` — missing → `UNAUTHENTICATED`; session → `TOKEN_RESTRICTED`; `Queue::assertNothingPushed()` | ✅ PASS (closed Fix 1) |
| **AUTH-23 / resend AC5**: suspended / deletion_pending → `403 ACCOUNT_*` on **resend** route | `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | `EmailVerificationTest.php:277-298` — suspended → `ACCOUNT_SUSPENDED`; deletion_pending → `ACCOUNT_PENDING_DELETION`; `Queue::assertNothingPushed()` | ✅ PASS (closed Fix 1) |
| **AUTH-23 / EV-05 / resend AC6–7**: 4th resend/h → `429 RATE_LIMIT_EXCEEDED` + `Retry-After`; hit before use case | 429 + Retry-After; attempts counted | `EmailVerificationTest.php:318-321`; `ThrottleEmailVerificationTest.php:58-74`, verify hit-before `:112-131` | ✅ PASS |
| **AUTH-24 / verify AC4**: revoke only presented bearer | bearer id deleted | `VerifyUserEmailTest.php:126` — `AuthTokenModel::where('id', bearerTokenId)->exists()`=`false` | ✅ PASS |
| **AUTH-12 / verify AC5**: no session issuance on verify | no session token / empty body | `EmailVerificationTest.php:74`, `:91-93`; `VerifyUserEmailTest.php:128` | ✅ PASS |
| **EV-08 / verify AC3**: atomic `used_at` | `used_at` set in txn | `VerifyUserEmailTest.php:124`; concurrency `EloquentEmailActionTokenRepositoryTest.php:278-279` — `[true, false]` | ✅ PASS |
| **EV-09 / verify AC7**: GET must not verify | 405 + status unchanged | `EmailVerificationTest.php:211-214` — status `405`, user still `pending_verification` | ✅ PASS |
| **EV-10 / verify AC8–9**: 6th verify → 429; validation → 422 without consume | 429 / `VALIDATION_FAILED` | `EmailVerificationTest.php:198-201`, `:142-155` | ✅ PASS |
| **EV-12**: already `active` → `403 EMAIL_ALREADY_VERIFIED` (verify + resend) | code exact | `EmailVerificationTest.php:128-130`, `:251-252`; `AuthErrorResponseFactoryTest.php:128-131` — `getMessage()` exact | ✅ PASS |
| **EV-12 AC3 / P2**: errors include `Cache-Control: private, no-store` + `request_id` | headers present | `EmailVerificationTest.php:71-73`, `:113`, `:234-235`; factory unit tests | ✅ PASS |
| **AUTH-25 AC2**: servers must not log query string with email token | access-log redaction / path-only | no app/nginx test evidence in slice | ⚠️ Spec-precision gap (infra outside API suite) |
| **EV-13 / EV-14**: discovered by `make test-backend`; final `make lint && make test-backend` green | suite green | Gate this run: lint PASS; **285 passed**, 0 failed | ✅ PASS |

**Status**: ✅ All precise ACs covered (Fix 1/2 closed) + ⚠️ Spec-precision gaps flagged (non-blocking)

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| M5 | `Exceptions/InvalidVerificationTokenException.php:24` | Message → `Bad token.` (mandatory re-include) | ✅ Killed — `AuthErrorResponseFactoryTest.php:113` + `EmailVerificationTest.php:111` |
| M1 | `UseCases/VerifyUserEmail.php:55` | Removed `revokeAuthToken->byId(...)` | ✅ Killed — `VerifyUserEmailTest.php:126` + `EmailVerificationTest.php:84` |
| M2 | `Domain/Enums/EmailActionPurpose.php:12` | TTL `3600` → `7200` | ✅ Killed — `EmailActionPurposeTest.php:13` + `IssueEmailVerificationTokenTest.php:44` |
| M3 | `ThrottleEmailVerificationResend.php:28` | Hardcoded `maxAttempts = 30` | ✅ Killed — `ThrottleEmailVerificationTest.php:69` + `EmailVerificationTest.php:318` |
| M4 | `UseCases/IssueEmailVerificationToken.php:31` | Skipped `invalidateUnusedForUser` | ✅ Killed — `IssueEmailVerificationTokenTest.php:84` + `ResendEmailVerificationTest.php:87` |
| M6 | `ThrottleEmailVerificationVerify.php:28` | Hardcoded `maxAttempts = 50` | ✅ Killed — `ThrottleEmailVerificationTest.php:130` + `EmailVerificationTest.php:198` |

**Sensor depth**: P0-full (6 manual behavior-level mutations; auth critical path)  
**Scratch**: temporary patches with immediate `git checkout --` restore; tree clean after sensor  
**Result**: 6/6 killed — PASS ✅

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
| Spec-anchored outcome check | ✅ (prior resend auth + exception message gaps closed) |
| Per-layer Coverage Expectation (routes happy+edge+error) | ✅ resend route now has authz/account error paths |
| Every test maps to a spec requirement | ✅ |
| Documented guidelines followed | ✅ `docs/testing.md`, `LARAVEL_CODE_DESIGN.md` |

---

## Edge Cases

- [x] Resend invalidates prior unused token before expiry — `ResendEmailVerificationTest.php:86-88`
- [x] Concurrent consume — one wins — `EloquentEmailActionTokenRepositoryTest.php:251-279`
- [ ] Expired bearer between resend and verify without consuming email token — **no dedicated test** (minor; optional)
- [x] Job/dispatch failure after register keeps pending — `RegisterUserTest.php:300-307`
- [x] Cross-user email token → invalid — `VerifyUserEmailTest.php:208-220`
- [ ] Whitespace-only token rejected by HTTP validation (no trim) — **not asserted** (`min:1` accepts `" "`) (minor; optional)
- [x] Mail sent with configured URL — `SendEmailVerificationJobTest.php:52-60`

---

## Gate Check

- **Gate command**: `make lint && make test-backend` (Final / Build from tasks.md)
- **Result**: lint PASS; **285** passed, **0** failed, **0** skipped
- **Test count before feature** (login validation): 225
- **Test count after feature** (prior FAIL): 283
- **Test count after Fix 1–2**: 285
- **Delta vs login**: +60; **Delta vs prior FAIL**: +2 (resend auth E2E cases)
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — prior Fix 1 (resend auth/account E2E) and Fix 2 (exception `getMessage()` OpenAPI binding / M5) closed by `b67d4f4`. Spec-precision gaps remain flagged only (non-blocking). Optional minor edges (whitespace token; expired bearer between resend/verify) unchanged from prior report.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| AUTH-12 | ✅ Verified (tests) | ✅ Verified |
| AUTH-20 | ⚠️ Partial (retry precision) | ⚠️ Partial (retry precision — flagged only) |
| AUTH-21 | ✅ Verified | ✅ Verified |
| AUTH-22 | ✅ Verified | ✅ Verified |
| AUTH-23 | ❌ Needs Fix (resend AC4–5) | ✅ Verified |
| AUTH-24 | ✅ Verified | ✅ Verified |
| AUTH-25 | ⚠️ Spec-precision (access logs) | ⚠️ Spec-precision (access logs — flagged only) |
| EV-01…EV-03 | ✅ Verified | ✅ Verified |
| EV-04 | ❌ Needs Fix (auth boundary on resend) | ✅ Verified |
| EV-05…EV-12 | ✅ Verified | ✅ Verified |
| EV-13, EV-14 | ✅ Verified (gate) | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 19/21 precise ACs matched; **0 AC gaps**; **2 spec-precision gaps** flagged (job retry assertion; access-log redaction)  
**Sensor**: 6/6 killed (M5 re-included and killed)  
**Gate**: 285 passed, 0 failed

**What works**: Happy verify/resend, TTL, invalidate-on-reissue, rate limits 3/h & 5/h, revoke bearer, no session on verify, Mail ciphertext, OpenAPI error codes/messages on verify+resend paths (incl. 401/TOKEN_RESTRICTED/ACCOUNT_*), registration pipeline wiring, exception↔OpenAPI message binding, final gate green.

**Issues found**: none blocking. Spec-precision + optional minor edges remain as flags only.

**Next steps**: Feature ready; handoff complete for Execute close-out.
